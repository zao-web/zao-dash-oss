<?php

use App\Models\GoalPeriod;

test('has guarded attributes empty', function () {
    expect((new GoalPeriod)->getGuarded())->toBe(['*']);
});

test('casts period_start to date', function () {
    $period = GoalPeriod::factory()->create(['period_start' => '2025-01-01']);

    expect($period->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $period = GoalPeriod::factory()->create(['period_end' => '2025-12-31']);

    expect($period->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts revenue_target to decimal', function () {
    $period = GoalPeriod::factory()->create(['revenue_target' => 50000.50]);

    expect($period->revenue_target)->toBeFloat();
});

test('casts revenue_actual to decimal', function () {
    $period = GoalPeriod::factory()->create(['revenue_actual' => 35000.25]);

    expect($period->revenue_actual)->toBeFloat();
});

test('casts pipeline_target to decimal', function () {
    $period = GoalPeriod::factory()->create(['pipeline_target' => 150000.00]);

    expect($period->pipeline_target)->toBeFloat();
});

test('casts pipeline_actual to decimal', function () {
    $period = GoalPeriod::factory()->create(['pipeline_actual' => 125000.00]);

    expect($period->pipeline_actual)->toBeFloat();
});

test('casts variance_pct to decimal', function () {
    $period = GoalPeriod::factory()->create(['variance_pct' => -10.50]);

    expect($period->variance_pct)->toBeFloat();
});

test('casts notes to array', function () {
    $period = GoalPeriod::factory()->create(['notes' => ['note1', 'note2']]);

    expect($period->notes)->toBeArray();
});

test('has status constants', function () {
    expect(GoalPeriod::STATUS_PENDING)->toBe('pending')
        ->and(GoalPeriod::STATUS_ON_TRACK)->toBe('on_track')
        ->and(GoalPeriod::STATUS_AHEAD)->toBe('ahead')
        ->and(GoalPeriod::STATUS_BEHIND)->toBe('behind')
        ->and(GoalPeriod::STATUS_CRITICAL)->toBe('critical')
        ->and(GoalPeriod::STATUS_COMPLETED)->toBe('completed');
});

test('has type constants', function () {
    expect(GoalPeriod::TYPE_YEARLY)->toBe('yearly')
        ->and(GoalPeriod::TYPE_QUARTERLY)->toBe('quarterly')
        ->and(GoalPeriod::TYPE_MONTHLY)->toBe('monthly')
        ->and(GoalPeriod::TYPE_WEEKLY)->toBe('weekly');
});

test('belongs to strategic goal relationship', function () {
    $period = GoalPeriod::factory()->create();

    expect($period->strategicGoal())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('scopeCurrent filters current periods', function () {
    $now = now();
    GoalPeriod::factory()->create([
        'period_start' => $now->copy()->subDays(5),
        'period_end' => $now->copy()->addDays(5),
    ]);
    GoalPeriod::factory()->create([
        'period_start' => $now->copy()->addDays(10),
        'period_end' => $now->copy()->addDays(20),
    ]);

    $current = GoalPeriod::current()->count();

    expect($current)->toBe(1);
});

test('scopePast filters past periods', function () {
    GoalPeriod::factory()->create(['period_end' => now()->subDay()]);
    GoalPeriod::factory()->create(['period_end' => now()->addDay()]);

    $past = GoalPeriod::past()->count();

    expect($past)->toBe(1);
});

test('scopeFuture filters future periods', function () {
    GoalPeriod::factory()->create(['period_start' => now()->addDay()]);
    GoalPeriod::factory()->create(['period_start' => now()->subDay()]);

    $future = GoalPeriod::future()->count();

    expect($future)->toBe(1);
});

test('scopeOfType filters by type', function () {
    GoalPeriod::factory()->create(['period_type' => 'monthly']);
    GoalPeriod::factory()->create(['period_type' => 'quarterly']);

    $monthly = GoalPeriod::ofType('monthly')->count();

    expect($monthly)->toBe(1);
});

test('can be created via factory', function () {
    $period = GoalPeriod::factory()->create();

    expect($period)->toBeInstanceOf(GoalPeriod::class)
        ->and($period->exists)->toBeTrue();
});
