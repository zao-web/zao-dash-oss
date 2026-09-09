<?php

use App\Models\FunnelMetrics;

test('table name is funnel_metrics', function () {
    expect((new FunnelMetrics)->getTable())->toBe('funnel_metrics');
});

test('has guarded attributes empty', function () {
    expect((new FunnelMetrics)->getGuarded())->toBe(['*']);
});

test('casts period_start to date', function () {
    $metrics = FunnelMetrics::factory()->create(['period_start' => '2025-01-01']);

    expect($metrics->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $metrics = FunnelMetrics::factory()->create(['period_end' => '2025-01-31']);

    expect($metrics->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts rates to decimal', function () {
    $metrics = FunnelMetrics::factory()->create([
        'new_to_qualified_rate' => 25.50,
        'qualified_to_proposal_rate' => 50.00,
        'proposal_to_negotiation_rate' => 75.25,
        'negotiation_to_won_rate' => 80.00,
    ]);

    expect($metrics->new_to_qualified_rate)->toBeFloat()
        ->and($metrics->qualified_to_proposal_rate)->toBeFloat()
        ->and($metrics->proposal_to_negotiation_rate)->toBeFloat()
        ->and($metrics->negotiation_to_won_rate)->toBeFloat();
});

test('casts revenue fields to decimal', function () {
    $metrics = FunnelMetrics::factory()->create([
        'avg_deal_size' => 25000.50,
        'revenue_won' => 100000.00,
        'revenue_lost' => 50000.00,
    ]);

    expect($metrics->avg_deal_size)->toBeFloat()
        ->and($metrics->revenue_won)->toBeFloat()
        ->and($metrics->revenue_lost)->toBeFloat();
});

test('has type constants', function () {
    expect(FunnelMetrics::TYPE_DAILY)->toBe('daily')
        ->and(FunnelMetrics::TYPE_WEEKLY)->toBe('weekly')
        ->and(FunnelMetrics::TYPE_MONTHLY)->toBe('monthly');
});

test('scopeLatest orders by period_end desc', function () {
    FunnelMetrics::factory()->create(['period_type' => 'weekly', 'period_end' => '2025-01-01']);
    FunnelMetrics::factory()->create(['period_type' => 'weekly', 'period_end' => '2025-02-01']);

    $latest = FunnelMetrics::latest('weekly')->first();

    expect($latest->period_end->toDateString())->toBe('2025-02-01');
});

test('scopeOfType filters by type', function () {
    FunnelMetrics::factory()->create(['period_type' => 'daily']);
    FunnelMetrics::factory()->create(['period_type' => 'weekly']);

    $daily = FunnelMetrics::ofType('daily')->count();

    expect($daily)->toBe(1);
});

test('can be created via factory', function () {
    $metrics = FunnelMetrics::factory()->create();

    expect($metrics)->toBeInstanceOf(FunnelMetrics::class)
        ->and($metrics->exists)->toBeTrue();
});
