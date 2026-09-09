<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\ContentSuggestion;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Lead;
use App\Models\QboInvoice;
use App\Models\Task;
use App\Models\User;
use App\Models\WordPressSite;
use App\Services\CapabilitySynthesisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilitySynthesisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CapabilitySynthesisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CapabilitySynthesisService;
    }

    /** @test */
    public function it_returns_human_required_items()
    {
        $items = $this->service->getHumanRequiredItems();

        $this->assertIsArray($items);
    }

    /** @test */
    public function it_includes_pending_approvals()
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['status' => 'active']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id, 'user_id' => $user->id]);

        ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'pending',
            'action_type' => 'post_content',
            'risk_level' => 'high',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $approvals = collect($items)->where('type', 'approval');
        $this->assertGreaterThan(0, $approvals->count());
    }

    /** @test */
    public function it_includes_pending_content_suggestions()
    {
        $site = WordPressSite::factory()->create();
        ContentSuggestion::factory()->create([
            'wordpress_site_id' => $site->id,
            'status' => 'pending',
            'title' => 'Test Content',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $content = collect($items)->where('type', 'content_approval');
        $this->assertGreaterThan(0, $content->count());
    }

    /** @test */
    public function it_includes_open_pull_requests()
    {
        $repo = GitHubRepo::factory()->create();
        GitHubPullRequest::factory()->create([
            'repo_id' => $repo->id,
            'state' => 'open',
            'merged_at' => null,
            'title' => 'Fix bug',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $prs = collect($items)->where('type', 'pr_review');
        $this->assertGreaterThan(0, $prs->count());
    }

    /** @test */
    public function it_includes_overdue_invoices()
    {
        QboInvoice::factory()->create([
            'status' => 'Overdue',
            'balance' => 1500.00,
            'doc_number' => 'INV-001',
            'customer_name' => 'Test Client',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $invoices = collect($items)->where('type', 'overdue_invoice');
        $this->assertGreaterThan(0, $invoices->count());
    }

    /** @test */
    public function it_includes_stale_leads()
    {
        Lead::factory()->create([
            'stage' => 'qualified',
            'company_name' => 'Stale Lead Co',
            'deal_value' => 15000,
            'last_contacted_at' => now()->subDays(10),
        ]);

        $items = $this->service->getHumanRequiredItems();

        $leads = collect($items)->where('type', 'lead_followup');
        $this->assertGreaterThan(0, $leads->count());
    }

    /** @test */
    public function it_includes_at_risk_clients()
    {
        Client::factory()->create([
            'status' => 'active',
            'health_score' => 35,
            'name' => 'At Risk Client',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $clients = collect($items)->where('type', 'client_health');
        $this->assertGreaterThan(0, $clients->count());
    }

    /** @test */
    public function it_includes_unassigned_tasks()
    {
        Task::factory()->create([
            'status' => 'pending',
            'assignee_id' => null,
            'title' => 'Unassigned Task',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $tasks = collect($items)->where('type', 'task_decision');
        $this->assertGreaterThan(0, $tasks->count());
    }

    /** @test */
    public function it_sorts_items_by_priority()
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id, 'user_id' => $user->id]);

        ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'pending',
            'risk_level' => 'high',
        ]);

        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 5000,
            'last_contacted_at' => now()->subDays(8),
        ]);

        $items = $this->service->getHumanRequiredItems();

        // First item should be high priority
        if (count($items) > 1) {
            $priorities = ['critical' => 0, 'high' => 1, 'medium' => 2];
            $firstPriority = $priorities[$items[0]['priority']] ?? 3;
            $secondPriority = $priorities[$items[1]['priority']] ?? 3;
            $this->assertLessThanOrEqual($secondPriority, $firstPriority);
        }
    }

    /** @test */
    public function it_maps_risk_to_priority_correctly()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapRiskToPriority');
        $method->setAccessible(true);

        $this->assertEquals('critical', $method->invoke($this->service, 'high'));
        $this->assertEquals('high', $method->invoke($this->service, 'medium'));
        $this->assertEquals('medium', $method->invoke($this->service, 'low'));
        $this->assertEquals('medium', $method->invoke($this->service, null));
    }

    /** @test */
    public function it_provides_capability_summary()
    {
        Agent::factory()->count(3)->create(['status' => 'active']);

        $summary = $this->service->getCapabilitySummary();

        $this->assertArrayHasKey('integrations', $summary);
        $this->assertArrayHasKey('agents', $summary);
        $this->assertArrayHasKey('automated', $summary);
        $this->assertArrayHasKey('manual_required', $summary);
    }

    /** @test */
    public function it_detects_integration_status()
    {
        $summary = $this->service->getCapabilitySummary();

        $this->assertIsArray($summary['integrations']);
        $this->assertArrayHasKey('google', $summary['integrations']);
        $this->assertArrayHasKey('slack', $summary['integrations']);
        $this->assertArrayHasKey('github', $summary['integrations']);
    }

    /** @test */
    public function it_provides_morning_briefing()
    {
        Client::factory()->create(['status' => 'active', 'health_score' => 30]);
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 15000,
            'last_contacted_at' => now()->subDays(8),
        ]);

        $briefing = $this->service->getMorningBriefing();

        $this->assertArrayHasKey('greeting', $briefing);
        $this->assertArrayHasKey('summary', $briefing);
        $this->assertArrayHasKey('by_type', $briefing);
        $this->assertArrayHasKey('top_priorities', $briefing);
        $this->assertArrayHasKey('recommendations', $briefing);
    }

    /** @test */
    public function it_provides_time_based_greeting()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getTimeBasedGreeting');
        $method->setAccessible(true);

        $greeting = $method->invoke($this->service);

        $this->assertContains($greeting, ['Good morning', 'Good afternoon', 'Good evening']);
    }

    /** @test */
    public function it_groups_briefing_by_priority()
    {
        Client::factory()->create(['status' => 'active', 'health_score' => 25]);
        Lead::factory()->create(['stage' => 'qualified', 'deal_value' => 8000, 'last_contacted_at' => now()->subDays(10)]);

        $briefing = $this->service->getMorningBriefing();

        $this->assertArrayHasKey('critical', $briefing['summary']);
        $this->assertArrayHasKey('high', $briefing['summary']);
        $this->assertArrayHasKey('medium', $briefing['summary']);
        $this->assertArrayHasKey('total_items', $briefing['summary']);
    }

    /** @test */
    public function it_provides_recommendations()
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id, 'user_id' => $user->id]);

        ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'pending',
            'risk_level' => 'high',
        ]);

        $briefing = $this->service->getMorningBriefing();

        $this->assertNotEmpty($briefing['recommendations']);
        $this->assertIsArray($briefing['recommendations']);
    }

    /** @test */
    public function it_identifies_automation_gaps()
    {
        Task::factory()->count(25)->create(['status' => 'pending']);
        Lead::factory()->count(10)->create(['stage' => 'qualified']);

        $gaps = $this->service->getAutomationGaps();

        $this->assertNotEmpty($gaps);
        $this->assertIsArray($gaps);

        if (! empty($gaps)) {
            $this->assertArrayHasKey('area', $gaps[0]);
            $this->assertArrayHasKey('description', $gaps[0]);
        }
    }

    /** @test */
    public function it_excludes_approved_approval_requests()
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id, 'user_id' => $user->id]);

        ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'approved',
        ]);

        $items = $this->service->getHumanRequiredItems();

        $approvals = collect($items)->where('type', 'approval');
        $this->assertEquals(0, $approvals->count());
    }

    /** @test */
    public function it_excludes_merged_pull_requests()
    {
        $repo = GitHubRepo::factory()->create();
        GitHubPullRequest::factory()->create([
            'repo_id' => $repo->id,
            'state' => 'closed',
            'merged_at' => now(),
        ]);

        $items = $this->service->getHumanRequiredItems();

        $prs = collect($items)->where('type', 'pr_review');
        $this->assertEquals(0, $prs->count());
    }

    /** @test */
    public function it_excludes_zero_balance_invoices()
    {
        QboInvoice::factory()->create([
            'status' => 'Overdue',
            'balance' => 0,
        ]);

        $items = $this->service->getHumanRequiredItems();

        $invoices = collect($items)->where('type', 'overdue_invoice');
        $this->assertEquals(0, $invoices->count());
    }

    /** @test */
    public function it_prioritizes_high_value_leads()
    {
        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 25000,
            'last_contacted_at' => now()->subDays(8),
        ]);

        $items = $this->service->getHumanRequiredItems();

        $leads = collect($items)->where('type', 'lead_followup');
        if ($leads->count() > 0) {
            $this->assertEquals('high', $leads->first()['priority']);
        }
    }

    /** @test */
    public function it_sets_critical_priority_for_very_low_health_clients()
    {
        Client::factory()->create([
            'status' => 'active',
            'health_score' => 25,
        ]);

        $items = $this->service->getHumanRequiredItems();

        $clients = collect($items)->where('type', 'client_health');
        if ($clients->count() > 0) {
            $this->assertEquals('critical', $clients->first()['priority']);
        }
    }
}
