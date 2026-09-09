<?php

use App\Models\BusinessGoal;

test('has guarded attributes empty', function () {
    expect((new BusinessGoal)->getGuarded())->toBe(['*']);
});

test('casts period_start to date', function () {
    $goal = BusinessGoal::factory()->create(['period_start' => '2025-01-01']);

    expect($goal->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $goal = BusinessGoal::factory()->create(['period_end' => '2025-12-31']);

    expect($goal->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts target to decimal', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100000.50]);

    expect($goal->target)->toBeFloat();
});

test('casts current_value to decimal', function () {
    $goal = BusinessGoal::factory()->create(['current_value' => 75000.25]);

    expect($goal->current_value)->toBeFloat();
});

test('has type constants', function () {
    expect(BusinessGoal::TYPE_REVENUE)->toBe('revenue')
        ->and(BusinessGoal::TYPE_PIPELINE)->toBe('pipeline')
        ->and(BusinessGoal::TYPE_CLIENTS)->toBe('clients')
        ->and(BusinessGoal::TYPE_WIN_RATE)->toBe('win_rate')
        ->and(BusinessGoal::TYPE_LEADS)->toBe('leads')
        ->and(BusinessGoal::TYPE_DEALS)->toBe('deals');
});

test('has period constants', function () {
    expect(BusinessGoal::PERIOD_MTD)->toBe('mtd')
        ->and(BusinessGoal::PERIOD_QTD)->toBe('qtd')
        ->and(BusinessGoal::PERIOD_YTD)->toBe('ytd')
        ->and(BusinessGoal::PERIOD_WEEKLY)->toBe('weekly')
        ->and(BusinessGoal::PERIOD_CUSTOM)->toBe('custom');
});

test('belongs to user relationship', function () {
    $goal = BusinessGoal::factory()->create();

    expect($goal->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getProgressAttribute calculates percentage', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100, 'current_value' => 75]);

    expect($goal->progress)->toBe(75.0);
});

test('getProgressAttribute caps at 100', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100, 'current_value' => 150]);

    expect($goal->progress)->toBe(100.0);
});

test('getRemainingAttribute calculates remaining', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100, 'current_value' => 75]);

    expect($goal->remaining)->toBe(25.0);
});

test('getIsAchievedAttribute returns true when target met', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100, 'current_value' => 100]);

    expect($goal->is_achieved)->toBeTrue();
});

test('getIsAchievedAttribute returns false when target not met', function () {
    $goal = BusinessGoal::factory()->create(['target' => 100, 'current_value' => 75]);

    expect($goal->is_achieved)->toBeFalse();
});

test('scopeActive filters active goals', function () {
    BusinessGoal::factory()->create(['status' => 'active']);
    BusinessGoal::factory()->create(['status' => 'inactive']);

    $active = BusinessGoal::active()->count();

    expect($active)->toBe(1);
});

test('scopeOfType filters by type', function () {
    BusinessGoal::factory()->create(['type' => 'revenue']);
    BusinessGoal::factory()->create(['type' => 'pipeline']);

    $revenue = BusinessGoal::ofType('revenue')->count();

    expect($revenue)->toBe(1);
});

test('can be created via factory', function () {
    $goal = BusinessGoal::factory()->create();

    expect($goal)->toBeInstanceOf(BusinessGoal::class)
        ->and($goal->exists)->toBeTrue();
});
