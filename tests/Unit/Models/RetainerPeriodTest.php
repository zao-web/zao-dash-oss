<?php

use App\Models\RetainerPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new RetainerPeriod)->getGuarded())->toBe([]);
});

test('casts hours_included to decimal', function () {
    $period = RetainerPeriod::factory()->create(['hours_included' => 40.50]);

    // Laravel's decimal:N cast returns a fixed-precision string.
    expect($period->hours_included)->toBe('40.50');
});

test('casts hours_used to decimal', function () {
    $period = RetainerPeriod::factory()->create(['hours_used' => 25.75]);

    expect($period->hours_used)->toBe('25.75');
});

test('casts rollover_hours to decimal', function () {
    $period = RetainerPeriod::factory()->create(['rollover_hours' => 10.00]);

    expect($period->rollover_hours)->toBe('10.00');
});

test('casts overage_rate to decimal', function () {
    $period = RetainerPeriod::factory()->create(['overage_rate' => 150.00]);

    expect($period->overage_rate)->toBe('150.00');
});

test('casts period_start to date', function () {
    $period = RetainerPeriod::factory()->create(['period_start' => '2025-01-01']);

    expect($period->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $period = RetainerPeriod::factory()->create(['period_end' => '2025-01-31']);

    expect($period->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to client relationship', function () {
    $period = RetainerPeriod::factory()->create();

    expect($period->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getTotalHoursAttribute calculates correctly', function () {
    $period = RetainerPeriod::factory()->create(['hours_included' => 40, 'rollover_hours' => 10]);

    expect($period->total_hours)->toBe(50.0);
});

test('getRemainingHoursAttribute calculates correctly', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 10,
        'hours_used' => 30,
    ]);

    expect($period->remaining_hours)->toBe(20.0);
});

test('getRemainingHoursAttribute returns zero when overused', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 50,
    ]);

    expect($period->remaining_hours)->toBe(0.0);
});

test('getUsagePercentAttribute calculates correctly', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 20,
    ]);

    expect($period->usage_percent)->toBe(50.0);
});

test('isOverage returns true when hours exceeded', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 45,
    ]);

    expect($period->isOverage())->toBeTrue();
});

test('isOverage returns false when within hours', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 30,
    ]);

    expect($period->isOverage())->toBeFalse();
});

test('getOverageHoursAttribute calculates correctly', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 45,
    ]);

    expect($period->overage_hours)->toBe(5.0);
});

test('getOverageHoursAttribute returns zero when no overage', function () {
    $period = RetainerPeriod::factory()->create([
        'hours_included' => 40,
        'rollover_hours' => 0,
        'hours_used' => 30,
    ]);

    expect($period->overage_hours)->toBe(0.0);
});

test('can be created via factory', function () {
    $period = RetainerPeriod::factory()->create();

    expect($period)->toBeInstanceOf(RetainerPeriod::class)
        ->and($period->exists)->toBeTrue();
});
