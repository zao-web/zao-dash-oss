<?php

use App\Models\StrategicGoal;

test('has guarded attributes empty', function () {
    expect((new StrategicGoal)->getGuarded())->toBe(['*']);
});

test('casts revenue_target to decimal', function () {
    $goal = StrategicGoal::factory()->create(['revenue_target' => 500000.75]);

    expect($goal->revenue_target)->toBeFloat();
});

test('casts margin_target_pct to decimal', function () {
    $goal = StrategicGoal::factory()->create(['margin_target_pct' => 25.50]);

    expect($goal->margin_target_pct)->toBeFloat();
});

test('casts profit_target to decimal', function () {
    $goal = StrategicGoal::factory()->create(['profit_target' => 100000.00]);

    expect($goal->profit_target)->toBeFloat();
});

test('casts assumptions to array', function () {
    $goal = StrategicGoal::factory()->create(['assumptions' => ['key' => 'value']]);

    expect($goal->assumptions)->toBeArray();
});

test('has status constants', function () {
    expect(StrategicGoal::STATUS_ACTIVE)->toBe('active')
        ->and(StrategicGoal::STATUS_ACHIEVED)->toBe('achieved')
        ->and(StrategicGoal::STATUS_MISSED)->toBe('missed')
        ->and(StrategicGoal::STATUS_ARCHIVED)->toBe('archived');
});

test('belongs to user relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many periods relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->periods())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has one yearly period relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->yearlyPeriod())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('has many quarters relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->quarters())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many months relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->months())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many weeks relationship', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal->weeks())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be created via factory', function () {
    $goal = StrategicGoal::factory()->create();

    expect($goal)->toBeInstanceOf(StrategicGoal::class)
        ->and($goal->exists)->toBeTrue();
});
