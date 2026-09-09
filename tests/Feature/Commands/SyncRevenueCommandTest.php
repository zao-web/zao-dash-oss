<?php

use App\Models\GoalPeriod;
use App\Models\HarvestInvoice;
use App\Models\StrategicGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command syncs revenue from paid harvest invoices to goal periods', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 10000,
        'state' => 'paid',
        'paid_at' => now()->subDays(1)->toDateString(),
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 5000,
        'state' => 'paid',
        'paid_at' => now()->subDays(5)->toDateString(),
    ]);

    $this->artisan('revenue:sync')
        ->assertExitCode(0);

    $period->refresh();
    expect((float) $period->revenue_actual)->toEqual(15000.0);
});

test('command ignores unpaid invoices', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 10000,
        'state' => 'paid',
        'paid_at' => now()->subDays(2)->toDateString(),
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 5000,
        'state' => 'open',
        'paid_at' => null,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 3000,
        'state' => 'draft',
        'paid_at' => null,
    ]);

    $this->artisan('revenue:sync')
        ->assertExitCode(0);

    $period->refresh();
    expect((float) $period->revenue_actual)->toEqual(10000.0);
});

test('command respects date field option for issue_date', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 8000,
        'state' => 'paid',
        'issue_date' => now()->subDays(5)->toDateString(),
        'paid_at' => now()->subMonths(2)->toDateString(),
    ]);

    $this->artisan('revenue:sync', ['--date-field' => 'issue_date'])
        ->assertExitCode(0);

    $period->refresh();
    expect((float) $period->revenue_actual)->toEqual(8000.0);
});

test('command validates date field option', function () {
    $this->artisan('revenue:sync', ['--date-field' => 'invalid_field'])
        ->expectsOutput("Invalid date field: invalid_field. Must be 'paid_at' or 'issue_date'")
        ->assertExitCode(1);
});

test('dry run mode shows changes without updating database', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 12000,
        'state' => 'paid',
        'paid_at' => now()->subDays(3)->toDateString(),
    ]);

    $this->artisan('revenue:sync', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run mode - no changes made')
        ->assertExitCode(0);

    $period->refresh();
    expect((float) $period->revenue_actual)->toEqual(0.0);
});

test('command shows message when no active goals found', function () {
    StrategicGoal::factory()->create(['status' => 'completed']);
    StrategicGoal::factory()->create(['status' => 'draft']);

    $this->artisan('revenue:sync')
        ->expectsOutput('No active goals found.')
        ->assertExitCode(0);
});

test('command can filter by specific goal id', function () {
    $goal1 = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $goal2 = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    $period1 = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal1->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    $period2 = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal2->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 7500,
        'state' => 'paid',
        'paid_at' => now()->subDays(2)->toDateString(),
    ]);

    $this->artisan('revenue:sync', ['--goal-id' => $goal1->id])
        ->assertExitCode(0);

    $period1->refresh();
    $period2->refresh();

    expect((float) $period1->revenue_actual)->toEqual(7500.0);
    expect((float) $period2->revenue_actual)->toEqual(0.0);
});

test('command does not update period when revenue unchanged', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'revenue_actual' => 5000,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 5000,
        'state' => 'paid',
        'paid_at' => now()->subDays(2)->toDateString(),
    ]);

    $this->artisan('revenue:sync')
        ->expectsOutputToContain('Unchanged: 1')
        ->assertExitCode(0);
});

test('command only includes invoices within period date range', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    $period = GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 10000,
        'state' => 'paid',
        'paid_at' => now()->subDays(5)->toDateString(),
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 8000,
        'state' => 'paid',
        'paid_at' => now()->subDays(45)->toDateString(),
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 6000,
        'state' => 'paid',
        'paid_at' => now()->addDays(45)->toDateString(),
    ]);

    $this->artisan('revenue:sync')
        ->assertExitCode(0);

    $period->refresh();
    expect((float) $period->revenue_actual)->toEqual(10000.0);
});

test('command shows revenue summary', function () {
    $goal = StrategicGoal::factory()->create([
        'status' => 'active',
        'fiscal_year' => now()->year,
    ]);

    GoalPeriod::factory()->create([
        'strategic_goal_id' => $goal->id,
        'period_type' => 'monthly',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'revenue_actual' => 0,
        'revenue_target' => 50000,
    ]);

    HarvestInvoice::factory()->create([
        'amount' => 15000,
        'state' => 'paid',
        'paid_at' => now()->subDays(2)->toDateString(),
    ]);

    $this->artisan('revenue:sync')
        ->expectsOutputToContain('Revenue Summary')
        ->expectsOutputToContain('MTD:')
        ->expectsOutputToContain('YTD:')
        ->assertExitCode(0);
});
