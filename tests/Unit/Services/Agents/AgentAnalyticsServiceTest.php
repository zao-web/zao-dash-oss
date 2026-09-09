<?php

namespace Tests\Unit\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AgentAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = new AgentAnalyticsService;
    }

    /** @test */
    public function it_gets_summary_metrics()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        AgentRun::factory()->count(10)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'cost_usd' => 0.01,
            'created_at' => now()->subDays(5),
        ]);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'cost_usd' => 0.005,
            'created_at' => now()->subDays(3),
        ]);

        $startDate = now()->subDays(30);
        $summary = $this->analytics->getSummaryMetrics($startDate);

        $this->assertEquals(13, $summary['total_runs']);
        $this->assertEquals(10, $summary['successful_runs']);
        $this->assertEquals(3, $summary['failed_runs']);
        $this->assertEquals(0.115, $summary['total_cost']); // 10 * 0.01 + 3 * 0.005
        $this->assertEquals(76.9, $summary['success_rate']); // 10/13 * 100
    }

    /** @test */
    public function it_calculates_average_cost_per_run()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.01,
            'created_at' => now(),
        ]);

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.03,
            'created_at' => now(),
        ]);

        $summary = $this->analytics->getSummaryMetrics(now()->subDay());

        $this->assertEquals(0.02, $summary['avg_cost_per_run']); // (0.01 + 0.03) / 2
    }

    /** @test */
    public function it_gets_cost_trend()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.01,
            'created_at' => now()->subDays(2)->startOfDay(),
        ]);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.02,
            'created_at' => now()->subDays(1)->startOfDay(),
        ]);

        $trend = $this->analytics->getCostTrend(now()->subDays(3));

        $this->assertCount(2, $trend);
        $this->assertEquals(0.05, $trend[0]['cost']); // 5 * 0.01
        $this->assertEquals(5, $trend[0]['runs']);
        $this->assertEquals(0.06, $trend[1]['cost']); // 3 * 0.02
        $this->assertEquals(3, $trend[1]['runs']);
    }

    /** @test */
    public function it_gets_usage_trend_by_status()
    {
        $agent = Agent::factory()->create();

        $date = now()->subDays(1)->startOfDay();

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'created_at' => $date,
        ]);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'created_at' => $date,
        ]);

        $trend = $this->analytics->getUsageTrend(now()->subDays(2));

        $this->assertNotEmpty($trend);
        $dayData = $trend[0];
        $this->assertEquals(5, $dayData['completed']);
        $this->assertEquals(2, $dayData['failed']);
    }

    /** @test */
    public function it_gets_metrics_by_agent()
    {
        $agent1 = Agent::factory()->create(['name' => 'Agent 1', 'slug' => 'agent-1']);
        $agent2 = Agent::factory()->create(['name' => 'Agent 2', 'slug' => 'agent-2']);

        AgentRun::factory()->count(10)->create([
            'agent_id' => $agent1->id,
            'status' => 'completed',
            'cost_usd' => 0.01,
            'input_tokens' => 100,
            'output_tokens' => 50,
        ]);

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent2->id,
            'status' => 'completed',
            'cost_usd' => 0.02,
            'input_tokens' => 200,
            'output_tokens' => 100,
        ]);

        $metrics = $this->analytics->getByAgentMetrics(now()->subDay());

        $this->assertCount(2, $metrics);
        $this->assertEquals(10, $metrics[0]['total_runs']);
        $this->assertEquals(0.1, $metrics[0]['total_cost']); // 10 * 0.01
        $this->assertEquals(1500, $metrics[0]['total_tokens']); // 10 * (100 + 50)
    }

    /** @test */
    public function it_calculates_success_rate_by_agent()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->count(7)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
        ]);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
        ]);

        $metrics = $this->analytics->getByAgentMetrics(now()->subDay());

        $this->assertEquals(70.0, $metrics[0]['success_rate']); // 7/10 * 100
    }

    /** @test */
    public function it_gets_metrics_by_source()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'invocation_source' => 'manual',
            'status' => 'completed',
            'cost_usd' => 0.01,
        ]);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'invocation_source' => 'scheduled',
            'status' => 'completed',
            'cost_usd' => 0.02,
        ]);

        $metrics = $this->analytics->getBySourceMetrics(now()->subDay());

        $this->assertCount(2, $metrics);

        $manual = collect($metrics)->firstWhere('source', 'manual');
        $this->assertEquals(5, $manual['count']);
        $this->assertEquals(0.05, $manual['cost']);
        $this->assertEquals(100.0, $manual['success_rate']);
    }

    /** @test */
    public function it_identifies_top_performers()
    {
        $agent1 = Agent::factory()->create(['name' => 'High Performer', 'slug' => 'high']);
        $agent2 = Agent::factory()->create(['name' => 'Low Performer', 'slug' => 'low']);

        // High performer: 9/10 success
        AgentRun::factory()->count(9)->create([
            'agent_id' => $agent1->id,
            'status' => 'completed',
        ]);
        AgentRun::factory()->create([
            'agent_id' => $agent1->id,
            'status' => 'failed',
        ]);

        // Low performer: 5/10 success
        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent2->id,
            'status' => 'completed',
        ]);
        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent2->id,
            'status' => 'failed',
        ]);

        $topPerformers = $this->analytics->getTopPerformers(now()->subDay(), 5);

        $this->assertGreaterThan(0, count($topPerformers));
        $this->assertEquals('high', $topPerformers[0]['slug']);
        $this->assertEquals(90.0, $topPerformers[0]['success_rate']);
    }

    /** @test */
    public function it_excludes_agents_with_insufficient_runs_from_top_performers()
    {
        $agent = Agent::factory()->create();

        // Only 3 runs (minimum is 5)
        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
        ]);

        $topPerformers = $this->analytics->getTopPerformers(now()->subDay(), 5);

        $this->assertEmpty($topPerformers);
    }

    /** @test */
    public function it_analyzes_failures()
    {
        $agent = Agent::factory()->create(['name' => 'Test Agent', 'slug' => 'test']);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'API timeout'],
        ]);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'Rate limit exceeded'],
        ]);

        $analysis = $this->analytics->getFailureAnalysis(now()->subDay());

        $this->assertEquals(5, $analysis['total_failures']);
        $this->assertNotEmpty($analysis['by_error_type']);
        $this->assertNotEmpty($analysis['recent_failures']);
    }

    /** @test */
    public function it_categorizes_error_types()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'Request timeout'],
        ]);

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'Rate limit: 429 Too Many Requests'],
        ]);

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'Permission denied'],
        ]);

        $analysis = $this->analytics->getFailureAnalysis(now()->subDay());

        $errorTypes = collect($analysis['by_error_type'])->pluck('error');
        $this->assertContains('timeout', $errorTypes);
        $this->assertContains('rate_limit', $errorTypes);
        $this->assertContains('permission', $errorTypes);
    }

    /** @test */
    public function it_gets_token_usage()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => 0.01,
        ]);

        $usage = $this->analytics->getTokenUsage(now()->subDay());

        $this->assertEquals(300, $usage['total_input']); // 3 * 100
        $this->assertEquals(150, $usage['total_output']); // 3 * 50
        $this->assertEquals(450, $usage['total']); // 300 + 150
    }

    /** @test */
    public function it_gets_token_usage_by_model()
    {
        $agent1 = Agent::factory()->create(['model' => 'sonnet']);
        $agent2 = Agent::factory()->create(['model' => 'opus']);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent1->id,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => 0.01,
        ]);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent2->id,
            'input_tokens' => 200,
            'output_tokens' => 100,
            'cost_usd' => 0.05,
        ]);

        $usage = $this->analytics->getTokenUsage(now()->subDay());

        $this->assertCount(2, $usage['by_model']);

        $sonnetUsage = collect($usage['by_model'])->firstWhere('model', 'sonnet');
        $this->assertEquals(200, $sonnetUsage['input_tokens']);
        $this->assertEquals(100, $sonnetUsage['output_tokens']);
    }

    /** @test */
    public function it_gets_agent_specific_analytics()
    {
        $agent = Agent::factory()->create(['name' => 'Test Agent', 'slug' => 'test']);

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'cost_usd' => 0.01,
            'input_tokens' => 100,
            'output_tokens' => 50,
        ]);

        $analytics = $this->analytics->getAgentAnalytics($agent, 30);

        $this->assertEquals('Test Agent', $analytics['agent']['name']);
        $this->assertEquals(5, $analytics['summary']['total_runs']);
        $this->assertEquals(5, $analytics['summary']['successful']);
        $this->assertEquals(0.05, $analytics['summary']['total_cost']);
        $this->assertEquals(750, $analytics['summary']['total_tokens']);
    }

    /** @test */
    public function it_includes_daily_stats_in_agent_analytics()
    {
        $agent = Agent::factory()->create();

        $date1 = now()->subDays(2)->startOfDay();
        $date2 = now()->subDays(1)->startOfDay();

        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'cost_usd' => 0.01,
            'created_at' => $date1,
        ]);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'cost_usd' => 0.02,
            'created_at' => $date2,
        ]);

        $analytics = $this->analytics->getAgentAnalytics($agent, 30);

        $this->assertCount(2, $analytics['daily']);
        $this->assertEquals(3, $analytics['daily'][0]['runs']);
        $this->assertEquals(0.03, $analytics['daily'][0]['cost']);
    }

    /** @test */
    public function it_includes_recent_runs_in_agent_analytics()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->count(25)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
        ]);

        $analytics = $this->analytics->getAgentAnalytics($agent, 30);

        // Should limit to 20 most recent
        $this->assertCount(20, $analytics['recent_runs']);
    }

    /** @test */
    public function it_handles_null_token_values()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);

        $usage = $this->analytics->getTokenUsage(now()->subDay());

        $this->assertEquals(0, $usage['total_input']);
        $this->assertEquals(0, $usage['total_output']);
    }

    /** @test */
    public function it_handles_no_data_gracefully()
    {
        $summary = $this->analytics->getSummaryMetrics(now()->subDays(30));

        $this->assertEquals(0, $summary['total_runs']);
        $this->assertEquals(0, $summary['total_cost']);
        $this->assertEquals(0, $summary['success_rate']);
    }

    /** @test */
    public function it_compares_with_previous_period()
    {
        $agent = Agent::factory()->create();

        // Previous period
        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.01,
            'created_at' => now()->subDays(60),
        ]);

        // Current period
        AgentRun::factory()->count(10)->create([
            'agent_id' => $agent->id,
            'cost_usd' => 0.01,
            'created_at' => now()->subDays(10),
        ]);

        $summary = $this->analytics->getSummaryMetrics(now()->subDays(30));

        $this->assertEquals(100.0, $summary['runs_change']); // 100% increase
        $this->assertEquals(100.0, $summary['cost_change']); // 100% increase
    }

    /** @test */
    public function it_extracts_error_messages_from_failed_runs()
    {
        $agent = Agent::factory()->create(['name' => 'Test', 'slug' => 'test']);

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'This is a very long error message that should be truncated to ensure it fits in the UI and does not overflow the display area with excessive text'],
        ]);

        $analysis = $this->analytics->getFailureAnalysis(now()->subDay());

        $recentFailure = $analysis['recent_failures'][0];
        $this->assertLessThanOrEqual(103, strlen($recentFailure['error'])); // 100 chars + '...'
    }
}
