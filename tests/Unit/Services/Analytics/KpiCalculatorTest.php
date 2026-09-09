<?php

namespace Tests\Unit\Services\Analytics;

use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\QboInvoice;
use App\Models\StrategicGoal;
use App\Models\TimeEntry;
use App\Services\Analytics\KpiCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected KpiCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new KpiCalculator;
    }

    /** @test */
    public function it_gets_dashboard_kpis()
    {
        $from = now()->startOfMonth();
        $to = now();

        $kpis = $this->calculator->getDashboardKpis($from, $to);

        $this->assertArrayHasKey('revenue', $kpis);
        $this->assertArrayHasKey('projects', $kpis);
        $this->assertArrayHasKey('clients', $kpis);
        $this->assertArrayHasKey('pipeline', $kpis);
        $this->assertArrayHasKey('team', $kpis);
        $this->assertArrayHasKey('agents', $kpis);
        $this->assertArrayHasKey('goals', $kpis);
    }

    /** @test */
    public function it_calculates_revenue_kpis()
    {
        $from = now()->startOfMonth();
        $to = now();

        QboInvoice::factory()->create([
            'invoice_date' => now(),
            'total_amount' => 5000,
        ]);

        QboInvoice::factory()->create([
            'invoice_date' => now(),
            'status' => 'paid',
            'paid_at' => now(),
            'total_amount' => 3000,
        ]);

        $revenue = $this->calculator->getRevenue($from, $to);

        $this->assertEquals(5000, $revenue['invoiced_mtd']);
        $this->assertEquals(3000, $revenue['paid_mtd']);
        $this->assertArrayHasKey('invoiced_change_pct', $revenue);
        $this->assertArrayHasKey('paid_change_pct', $revenue);
        $this->assertArrayHasKey('outstanding', $revenue);
    }

    /** @test */
    public function it_falls_back_to_harvest_for_revenue()
    {
        $from = now()->startOfMonth();
        $to = now();

        HarvestInvoice::factory()->create([
            'issue_date' => now(),
            'amount' => 2500,
        ]);

        $revenue = $this->calculator->getRevenue($from, $to);

        $this->assertEquals(2500, $revenue['invoiced_mtd']);
    }

    /** @test */
    public function it_calculates_project_stats()
    {
        Project::factory()->count(5)->create(['status' => 'active']);
        Project::factory()->count(2)->create([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        Project::factory()->count(2)->create([
            'status' => 'active',
            'health_score' => 50,
        ]);

        $stats = $this->calculator->getProjectStats(now()->startOfMonth(), now());

        $this->assertEquals(5, $stats['active']);
        $this->assertEquals(2, $stats['completed_period']);
        $this->assertEquals(2, $stats['at_risk']);
        $this->assertIsFloat($stats['average_health']);
    }

    /** @test */
    public function it_calculates_client_stats()
    {
        Client::factory()->count(10)->create(['status' => 'active', 'health_score' => 85]);
        Client::factory()->count(3)->create([
            'status' => 'active',
            'health_score' => 65,
            'created_at' => now(),
        ]);
        Client::factory()->count(2)->create(['status' => 'active', 'health_score' => 50]);

        $stats = $this->calculator->getClientStats(now()->startOfMonth(), now());

        $this->assertEquals(15, $stats['total_active']);
        $this->assertEquals(3, $stats['new_period']);
        $this->assertEquals(10, $stats['health_distribution']['healthy']);
        $this->assertEquals(3, $stats['health_distribution']['moderate']);
        $this->assertEquals(2, $stats['health_distribution']['at_risk']);
    }

    /** @test */
    public function it_calculates_pipeline_stats()
    {
        Lead::factory()->create([
            'status' => 'new',
            'estimated_value' => 5000,
            'created_at' => now(),
        ]);

        Lead::factory()->create([
            'status' => 'qualified',
            'estimated_value' => 10000,
            'created_at' => now(),
        ]);

        Lead::factory()->create([
            'status' => 'won',
            'actual_value' => 15000,
            'won_at' => now(),
            'created_at' => now()->subDays(30),
        ]);

        Lead::factory()->create([
            'status' => 'lost',
            'lost_at' => now(),
            'created_at' => now()->subDays(20),
        ]);

        $stats = $this->calculator->getPipelineStats(now()->startOfMonth(), now());

        $this->assertEquals(15000, $stats['pipeline_value']);
        $this->assertEquals(1, $stats['leads_won']);
        $this->assertEquals(1, $stats['leads_lost']);
        $this->assertEquals(50, $stats['win_rate']);
        $this->assertIsFloat($stats['average_deal_size']);
    }

    /** @test */
    public function it_calculates_team_stats()
    {
        TimeEntry::factory()->create([
            'date' => now(),
            'hours' => 8,
            'billable' => true,
        ]);

        TimeEntry::factory()->create([
            'date' => now(),
            'hours' => 2,
            'billable' => false,
        ]);

        $stats = $this->calculator->getTeamStats(now()->startOfMonth(), now());

        $this->assertEquals(10, $stats['total_hours']);
        $this->assertEquals(8, $stats['billable_hours']);
        $this->assertEquals(80, $stats['utilization_rate']);
    }

    /** @test */
    public function it_calculates_agent_stats()
    {
        AgentRun::factory()->count(5)->create([
            'status' => 'completed',
            'cost_usd' => 0.50,
            'created_at' => now(),
        ]);

        AgentRun::factory()->count(2)->create([
            'status' => 'failed',
            'cost_usd' => 0.25,
            'created_at' => now(),
        ]);

        ApprovalRequest::factory()->count(3)->create(['status' => 'pending']);

        $stats = $this->calculator->getAgentStats(now()->startOfMonth(), now());

        $this->assertEquals(7, $stats['total_runs']);
        $this->assertEquals(5, $stats['successful']);
        $this->assertEquals(2, $stats['failed']);
        $this->assertEquals(71.4, $stats['success_rate']);
        $this->assertEquals(3.00, $stats['total_cost']);
        $this->assertEquals(3, $stats['pending_approvals']);
    }

    /** @test */
    public function it_calculates_goal_progress()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'status' => 'active',
            'revenue_target' => 100000,
        ]);

        QboInvoice::factory()->create([
            'invoice_date' => now(),
            'total_amount' => 25000,
        ]);

        $progress = $this->calculator->getGoalProgress();

        $this->assertTrue($progress['has_goal']);
        $this->assertEquals(100000, $progress['target_revenue']);
        $this->assertEquals(25000, $progress['current_revenue']);
        $this->assertEquals(25, $progress['progress_pct']);
    }

    /** @test */
    public function it_returns_no_goal_when_not_set()
    {
        $progress = $this->calculator->getGoalProgress();

        $this->assertFalse($progress['has_goal']);
    }

    /** @test */
    public function it_determines_if_goal_is_on_track()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'status' => 'active',
            'revenue_target' => 100000,
        ]);

        // Simulate 50% through year with 50% revenue (on track)
        Carbon::setTestNow(now()->setMonth(6)->setDay(15));

        QboInvoice::factory()->create([
            'invoice_date' => now(),
            'total_amount' => 50000,
        ]);

        $progress = $this->calculator->getGoalProgress();

        $this->assertTrue($progress['on_track']);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_gets_outstanding_revenue()
    {
        QboInvoice::factory()->create([
            'status' => 'sent',
            'balance' => 5000,
        ]);

        QboInvoice::factory()->create([
            'status' => 'overdue',
            'balance' => 3000,
        ]);

        $revenue = $this->calculator->getRevenue(now()->startOfMonth(), now());

        $this->assertEquals(8000, $revenue['outstanding']);
    }

    /** @test */
    public function it_calculates_percent_change()
    {
        $from = now()->startOfMonth();
        $to = now();

        // Previous period: $1000
        QboInvoice::factory()->create([
            'invoice_date' => now()->subMonth(),
            'total_amount' => 1000,
        ]);

        // Current period: $1500
        QboInvoice::factory()->create([
            'invoice_date' => now(),
            'total_amount' => 1500,
        ]);

        $revenue = $this->calculator->getRevenue($from, $to);

        // Should show increase
        $this->assertGreaterThan(0, $revenue['invoiced_change_pct']);
    }

    /** @test */
    public function it_gets_hours_by_project()
    {
        $project = Project::factory()->create(['name' => 'Test Project']);

        TimeEntry::factory()->create([
            'project_id' => $project->id,
            'date' => now(),
            'hours' => 10,
        ]);

        $stats = $this->calculator->getTeamStats(now()->startOfMonth(), now());

        $this->assertNotEmpty($stats['by_project']);
        $this->assertEquals('Test Project', $stats['by_project'][0]['project']);
        $this->assertEquals(10, $stats['by_project'][0]['hours']);
    }

    /** @test */
    public function it_gets_top_agents()
    {
        AgentRun::factory()->count(10)->create([
            'agent_id' => 1,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        AgentRun::factory()->count(5)->create([
            'agent_id' => 2,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $stats = $this->calculator->getAgentStats(now()->startOfMonth(), now());

        $this->assertNotEmpty($stats['top_agents']);
        $this->assertEquals(1, $stats['top_agents'][0]['agent_id']);
        $this->assertEquals(10, $stats['top_agents'][0]['runs']);
    }

    /** @test */
    public function it_calculates_average_sales_cycle()
    {
        Lead::factory()->create([
            'status' => 'won',
            'created_at' => now()->subDays(30),
            'won_at' => now(),
        ]);

        Lead::factory()->create([
            'status' => 'won',
            'created_at' => now()->subDays(60),
            'won_at' => now(),
        ]);

        $stats = $this->calculator->getPipelineStats(now()->startOfMonth(), now());

        $this->assertEquals(45, $stats['average_sales_cycle_days']);
    }

    /** @test */
    public function it_caches_dashboard_kpis()
    {
        $from = now()->startOfMonth();
        $to = now();

        // First call should calculate
        $kpis1 = $this->calculator->getDashboardKpis($from, $to);

        // Create new data
        Client::factory()->create(['status' => 'active']);

        // Second call should return cached (same client count)
        $kpis2 = $this->calculator->getDashboardKpis($from, $to);

        $this->assertEquals($kpis1, $kpis2);
    }

    /** @test */
    public function it_clears_cache()
    {
        $from = now()->startOfMonth();
        $to = now();

        $this->calculator->getDashboardKpis($from, $to);
        $this->calculator->clearCache();

        // After clearing, should recalculate
        Client::factory()->create(['status' => 'active']);
        $kpis = $this->calculator->getDashboardKpis($from, $to);

        $this->assertIsArray($kpis);
    }
}
