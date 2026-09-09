<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\ProactiveInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProactiveInsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProactiveInsightsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProactiveInsightsService;
    }

    /** @test */
    public function it_returns_insights_array()
    {
        $insights = $this->service->getInsights();

        $this->assertIsArray($insights);
    }

    /** @test */
    public function it_limits_insights_count()
    {
        // Create many potential insights
        Client::factory()->count(10)->create(['status' => 'active', 'industry' => 'Tech']);
        Lead::factory()->count(10)->create(['stage' => 'qualified', 'deal_value' => 15000]);

        $insights = $this->service->getInsights(3);

        $this->assertCount(3, $insights);
    }

    /** @test */
    public function it_detects_slack_opportunity_signals()
    {
        $workspace = SlackWorkspace::factory()->create();
        $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

        SlackMessage::factory()->create([
            'channel_id' => $channel->id,
            'channel_name' => 'client-acme',
            'text' => 'We have more budget available for expansion',
            'created_at' => now()->subHours(2),
        ]);

        $insights = $this->service->getInsights(10);

        $opportunityInsights = collect($insights)->where('type', 'slack_opportunity');
        $this->assertGreaterThan(0, $opportunityInsights->count());
    }

    /** @test */
    public function it_detects_slack_risk_signals()
    {
        $workspace = SlackWorkspace::factory()->create();
        $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

        SlackMessage::factory()->create([
            'channel_id' => $channel->id,
            'channel_name' => 'client-xyz',
            'text' => 'Client is frustrated with the delay',
            'created_at' => now()->subHours(1),
        ]);

        $insights = $this->service->getInsights(10);

        $riskInsights = collect($insights)->where('type', 'slack_risk');
        $this->assertGreaterThan(0, $riskInsights->count());
    }

    /** @test */
    public function it_detects_industry_patterns()
    {
        Client::factory()->count(3)->create([
            'status' => 'active',
            'industry' => 'Healthcare',
            'created_at' => now()->subMonths(2),
        ]);

        $insights = $this->service->getInsights(10);

        $patternInsights = collect($insights)->where('type', 'pattern_vertical');
        $this->assertGreaterThan(0, $patternInsights->count());
    }

    /** @test */
    public function it_detects_completed_project_opportunities()
    {
        $client = Client::factory()->create(['status' => 'active']);
        Project::factory()->create([
            'client_id' => $client->id,
            'status' => 'completed',
            'updated_at' => now()->subDays(5),
        ]);

        $insights = $this->service->getInsights(10);

        $caseStudyInsights = collect($insights)->where('type', 'project_case_study');
        $this->assertGreaterThan(0, $caseStudyInsights->count());
    }

    /** @test */
    public function it_detects_hot_leads()
    {
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 25000,
            'company_name' => 'Hot Lead Inc',
            'updated_at' => now()->subDays(3),
        ]);

        $insights = $this->service->getInsights(10);

        $hotLeadInsights = collect($insights)->where('type', 'lead_hot');
        $this->assertGreaterThan(0, $hotLeadInsights->count());
    }

    /** @test */
    public function it_detects_stale_high_value_leads()
    {
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 30000,
            'company_name' => 'Stale Lead Corp',
            'last_contacted_at' => now()->subDays(20),
        ]);

        $insights = $this->service->getInsights(10);

        $staleLeadInsights = collect($insights)->where('type', 'lead_stale');
        $this->assertGreaterThan(0, $staleLeadInsights->count());
    }

    /** @test */
    public function it_detects_at_risk_clients()
    {
        Client::factory()->create([
            'status' => 'active',
            'health_score' => 30,
            'name' => 'At Risk Client',
        ]);

        $insights = $this->service->getInsights(10);

        $atRiskInsights = collect($insights)->where('type', 'client_at_risk');
        $this->assertGreaterThan(0, $atRiskInsights->count());
    }

    /** @test */
    public function it_sorts_insights_by_priority_and_recency()
    {
        $client = Client::factory()->create(['status' => 'active', 'health_score' => 20]);

        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 15000,
            'updated_at' => now()->subDays(5),
        ]);

        $insights = $this->service->getInsights(10);

        // First insight should have highest combined priority + recency score
        if (count($insights) > 1) {
            $firstScore = ($insights[0]['priority'] ?? 0) + ($insights[0]['recency_score'] ?? 0);
            $secondScore = ($insights[1]['priority'] ?? 0) + ($insights[1]['recency_score'] ?? 0);
            $this->assertGreaterThanOrEqual($secondScore, $firstScore);
        }
    }

    /** @test */
    public function it_calculates_recency_score_correctly()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('recencyScore');
        $method->setAccessible(true);

        // Recent (< 6 hours)
        $score = $method->invoke($this->service, now()->subHours(3));
        $this->assertEquals(30, $score);

        // Within day
        $score = $method->invoke($this->service, now()->subHours(12));
        $this->assertEquals(25, $score);

        // Within 3 days
        $score = $method->invoke($this->service, now()->subDays(2));
        $this->assertEquals(20, $score);

        // Within week
        $score = $method->invoke($this->service, now()->subDays(5));
        $this->assertEquals(15, $score);

        // Older
        $score = $method->invoke($this->service, now()->subDays(10));
        $this->assertEquals(10, $score);

        // Null date
        $score = $method->invoke($this->service, null);
        $this->assertEquals(0, $score);
    }

    /** @test */
    public function it_includes_required_insight_properties()
    {
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 20000,
        ]);

        $insights = $this->service->getInsights(10);

        if (! empty($insights)) {
            $insight = $insights[0];
            $this->assertArrayHasKey('type', $insight);
            $this->assertArrayHasKey('title', $insight);
            $this->assertArrayHasKey('subtitle', $insight);
            $this->assertArrayHasKey('action', $insight);
            $this->assertArrayHasKey('action_label', $insight);
            $this->assertArrayHasKey('priority', $insight);
        }
    }

    /** @test */
    public function it_extracts_client_from_channel_name()
    {
        $client = Client::factory()->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractClientFromChannel');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'acme-corp');
        $this->assertEquals('Acme Corp', $result);
    }

    /** @test */
    public function it_returns_null_for_no_matching_client()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractClientFromChannel');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'nonexistent-client');
        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_missing_slack_messages_gracefully()
    {
        // Don't create any Slack data
        $insights = $this->service->getInsights(10);

        // Should not throw error, just return other insights
        $this->assertIsArray($insights);
    }

    /** @test */
    public function it_filters_old_slack_messages()
    {
        $workspace = SlackWorkspace::factory()->create();
        $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

        // Old message (> 7 days)
        SlackMessage::factory()->create([
            'channel_id' => $channel->id,
            'text' => 'budget expansion',
            'created_at' => now()->subDays(10),
        ]);

        $insights = $this->service->getInsights(10);

        // Should not include old Slack messages
        $slackInsights = collect($insights)->where('type', 'slack_opportunity');
        $this->assertEquals(0, $slackInsights->count());
    }

    /** @test */
    public function it_ignores_low_health_score_inactive_clients()
    {
        Client::factory()->create([
            'status' => 'inactive',
            'health_score' => 20,
        ]);

        $insights = $this->service->getInsights(10);

        $atRiskInsights = collect($insights)->where('type', 'client_at_risk');
        $this->assertEquals(0, $atRiskInsights->count());
    }

    /** @test */
    public function it_respects_deal_value_threshold_for_hot_leads()
    {
        // Low value lead
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 5000,
            'updated_at' => now()->subDays(2),
        ]);

        $insights = $this->service->getInsights(10);

        $hotLeadInsights = collect($insights)->where('type', 'lead_hot');
        $this->assertEquals(0, $hotLeadInsights->count());
    }
}
