<?php

namespace Tests\Unit\Services;

use App\Models\FunnelMetrics;
use App\Models\GoalPeriod;
use App\Models\Lead;
use App\Models\QboInvoice;
use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BusinessIntelligenceService $bi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bi = new BusinessIntelligenceService;
    }

    /** @test */
    public function it_calculates_funnel_metrics()
    {
        $start = now()->startOfMonth();
        $end = now();

        // Create leads at different stages
        Lead::factory()->create(['stage' => 'new', 'created_at' => now()]);
        Lead::factory()->create(['stage' => 'qualified', 'created_at' => now()]);
        Lead::factory()->create(['stage' => 'proposal', 'created_at' => now()]);
        Lead::factory()->create(['stage' => 'negotiation', 'created_at' => now()]);
        Lead::factory()->create([
            'stage' => 'won',
            'deal_value' => 10000,
            'created_at' => now()->subDays(30),
            'converted_at' => now(),
        ]);

        $metrics = $this->bi->calculateFunnelMetrics($start, $end);

        $this->assertArrayHasKey('new_to_qualified_rate', $metrics);
        $this->assertArrayHasKey('qualified_to_proposal_rate', $metrics);
        $this->assertArrayHasKey('overall_win_rate', $metrics);
        $this->assertArrayHasKey('avg_deal_size', $metrics);
        $this->assertArrayHasKey('pipeline_value', $metrics);
        $this->assertEquals(5, $metrics['leads_created']);
        $this->assertEquals(1, $metrics['deals_won']);
    }

    /** @test */
    public function it_calculates_conversion_rates()
    {
        $start = now()->startOfMonth();
        $end = now();

        // 100 new leads
        Lead::factory()->count(100)->create([
            'stage' => 'new',
            'created_at' => now(),
        ]);

        // 50 make it to qualified
        Lead::factory()->count(50)->create([
            'stage' => 'qualified',
            'created_at' => now(),
        ]);

        $metrics = $this->bi->calculateFunnelMetrics($start, $end);

        // 50/100 = 50% conversion from new to qualified
        $this->assertEquals(50.00, $metrics['new_to_qualified_rate']);
    }

    /** @test */
    public function it_calculates_win_rate()
    {
        $start = now()->startOfMonth();
        $end = now();

        Lead::factory()->count(7)->create([
            'stage' => 'won',
            'converted_at' => now(),
        ]);

        Lead::factory()->count(3)->create([
            'stage' => 'lost',
            'updated_at' => now(),
        ]);

        $metrics = $this->bi->calculateFunnelMetrics($start, $end);

        $this->assertEquals(70.00, $metrics['overall_win_rate']);
    }

    /** @test */
    public function it_calculates_weighted_pipeline()
    {
        Lead::factory()->create([
            'stage' => 'new',
            'deal_value' => 10000,
        ]);

        Lead::factory()->create([
            'stage' => 'qualified',
            'deal_value' => 10000,
        ]);

        Lead::factory()->create([
            'stage' => 'proposal',
            'deal_value' => 10000,
        ]);

        Lead::factory()->create([
            'stage' => 'negotiation',
            'deal_value' => 10000,
        ]);

        $metrics = $this->bi->calculateFunnelMetrics(now()->startOfMonth(), now());

        // new(10%) + qualified(25%) + proposal(50%) + negotiation(75%) = 16000
        $this->assertEquals(16000.00, $metrics['weighted_pipeline']);
    }

    /** @test */
    public function it_stores_funnel_snapshot()
    {
        $start = now()->startOfMonth();
        $end = now();

        Lead::factory()->count(5)->create(['created_at' => now()]);

        $snapshot = $this->bi->storeFunnelSnapshot('monthly', $start, $end);

        $this->assertDatabaseHas('funnel_metrics', [
            'period_type' => 'monthly',
            'period_start' => $start->toDateString(),
            'leads_created' => 5,
        ]);
    }

    /** @test */
    public function it_calculates_required_leads_per_week()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 25000,
        ]);

        // Mock historical rates
        FunnelMetrics::factory()->create([
            'period_type' => 'monthly',
            'overall_win_rate' => 25,
            'avg_deal_size' => 10000,
            'avg_sales_cycle_days' => 45,
        ]);

        $requirements = $this->bi->calculateRequiredLeadsPerWeek($goal);

        $this->assertArrayHasKey('revenue_remaining', $requirements);
        $this->assertArrayHasKey('deals_needed', $requirements);
        $this->assertArrayHasKey('leads_per_week', $requirements);
        $this->assertArrayHasKey('assumptions', $requirements);
        $this->assertEquals(75000, $requirements['revenue_remaining']);
    }

    /** @test */
    public function it_uses_fallback_assumptions_when_no_history()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        $requirements = $this->bi->calculateRequiredLeadsPerWeek($goal);

        $this->assertArrayHasKey('assumptions', $requirements);
        $this->assertIsFloat($requirements['assumptions']['win_rate']);
        $this->assertIsFloat($requirements['assumptions']['avg_deal_size']);
    }

    /** @test */
    public function it_calculates_goal_progress()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        QboInvoice::factory()->create([
            'status' => 'paid',
            'txn_date' => now(),
            'total_amount' => 30000,
        ]);

        Lead::factory()->count(50)->create([
            'created_at' => Carbon::create(now()->year, 1, 1),
        ]);

        Lead::factory()->count(10)->create([
            'stage' => 'won',
            'deal_value' => 3000,
            'converted_at' => now(),
        ]);

        $progress = $this->bi->calculateProgress($goal);

        $this->assertArrayHasKey('revenue', $progress);
        $this->assertArrayHasKey('pace', $progress);
        $this->assertArrayHasKey('leads', $progress);
        $this->assertArrayHasKey('deals', $progress);
        $this->assertArrayHasKey('pipeline', $progress);
        $this->assertEquals(30000, $progress['revenue']['actual']);
        $this->assertEquals(10, $progress['deals']['actual']);
    }

    /** @test */
    public function it_identifies_on_track_status()
    {
        Carbon::setTestNow(now()->setMonth(6));

        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        QboInvoice::factory()->create([
            'status' => 'paid',
            'txn_date' => now(),
            'total_amount' => 50000, // 50% at 50% through year
        ]);

        $progress = $this->bi->calculateProgress($goal);

        $this->assertEquals('on_track', $progress['pace']['status']);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_identifies_behind_status()
    {
        Carbon::setTestNow(now()->setMonth(6));

        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        QboInvoice::factory()->create([
            'status' => 'paid',
            'txn_date' => now(),
            'total_amount' => 30000, // Only 30% at 50% through year
        ]);

        $progress = $this->bi->calculateProgress($goal);

        $this->assertEquals('behind', $progress['pace']['status']);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_identifies_levers_when_behind()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 20000, // Behind
        ]);

        Lead::factory()->count(10)->create(['created_at' => now()]);

        $levers = $this->bi->identifyLevers($goal);

        $this->assertArrayHasKey('status', $levers);
        $this->assertArrayHasKey('levers', $levers);
        $this->assertArrayHasKey('revenue_gap', $levers);
        $this->assertNotEmpty($levers['levers']);
    }

    /** @test */
    public function it_suggests_increase_leads_lever()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 200000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 10000,
        ]);

        $levers = $this->bi->identifyLevers($goal);

        $increaseLever = collect($levers['levers'])->firstWhere('lever', 'increase_leads');
        $this->assertNotNull($increaseLever);
        $this->assertArrayHasKey('target', $increaseLever);
        $this->assertArrayHasKey('actions', $increaseLever);
    }

    /** @test */
    public function it_suggests_improve_win_rate_lever()
    {
        FunnelMetrics::factory()->create([
            'period_type' => 'monthly',
            'overall_win_rate' => 20, // Low win rate
        ]);

        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 200000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 10000,
        ]);

        $levers = $this->bi->identifyLevers($goal);

        $winRateLever = collect($levers['levers'])->firstWhere('lever', 'improve_win_rate');
        $this->assertNotNull($winRateLever);
    }

    /** @test */
    public function it_analyzes_capacity()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        Lead::factory()->count(30)->create(['stage' => 'proposal']);

        $capacity = $this->bi->analyzeCapacity($goal);

        $this->assertArrayHasKey('lead_generation', $capacity);
        $this->assertArrayHasKey('pipeline_management', $capacity);
        $this->assertArrayHasKey('recommendation', $capacity);
        $this->assertEquals(30, $capacity['pipeline_management']['active_leads']);
    }

    /** @test */
    public function it_identifies_capacity_overload()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 500000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 0,
        ]);

        $capacity = $this->bi->analyzeCapacity($goal);

        $this->assertTrue($capacity['lead_generation']['is_overloaded']);
    }

    /** @test */
    public function it_forecasts_revenue()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        QboInvoice::factory()->create([
            'status' => 'paid',
            'txn_date' => now(),
            'total_amount' => 25000,
        ]);

        Lead::factory()->create([
            'stage' => 'negotiation',
            'deal_value' => 50000,
        ]);

        FunnelMetrics::factory()->create([
            'period_type' => 'monthly',
            'overall_win_rate' => 25,
            'avg_sales_cycle_days' => 45,
        ]);

        $forecast = $this->bi->forecastRevenue($goal, 90);

        $this->assertArrayHasKey('forecasts', $forecast);
        $this->assertArrayHasKey('year_end', $forecast);
        $this->assertArrayHasKey(30, $forecast['forecasts']);
        $this->assertArrayHasKey(60, $forecast['forecasts']);
        $this->assertArrayHasKey(90, $forecast['forecasts']);
    }

    /** @test */
    public function it_updates_goal_actuals()
    {
        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
        ]);

        $period = GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'monthly',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);

        QboInvoice::factory()->create([
            'status' => 'paid',
            'txn_date' => now(),
            'total_amount' => 5000,
        ]);

        Lead::factory()->count(10)->create(['created_at' => now()]);
        Lead::factory()->count(2)->create([
            'stage' => 'won',
            'converted_at' => now(),
        ]);

        $this->bi->updateGoalActuals($goal);

        $period->refresh();
        $this->assertEquals(5000, $period->revenue_actual);
        $this->assertEquals(10, $period->leads_actual);
        $this->assertEquals(2, $period->closed_deals_actual);
    }

    /** @test */
    public function it_returns_no_levers_when_on_track()
    {
        Carbon::setTestNow(now()->setMonth(6));

        $goal = StrategicGoal::factory()->create([
            'fiscal_year' => now()->year,
            'revenue_target' => 100000,
        ]);

        GoalPeriod::factory()->create([
            'goal_id' => $goal->id,
            'period_type' => 'yearly',
            'revenue_actual' => 50000, // On track
        ]);

        $levers = $this->bi->identifyLevers($goal);

        $this->assertEquals('on_track', $levers['status']);
        $this->assertEmpty($levers['levers']);

        Carbon::setTestNow();
    }
}
