<?php

use App\Models\ProjectBudget;

test('has guarded attributes empty', function () {
    expect((new ProjectBudget)->getGuarded())->toBe(['*']);
});

test('casts budget_hours to decimal', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 160.00]);

    expect($budget->budget_hours)->toBeFloat();
});

test('casts budget_amount to decimal', function () {
    $budget = ProjectBudget::factory()->create(['budget_amount' => 20000.00]);

    expect($budget->budget_amount)->toBeFloat();
});

test('casts hours_logged to decimal', function () {
    $budget = ProjectBudget::factory()->create(['hours_logged' => 80.50]);

    expect($budget->hours_logged)->toBeFloat();
});

test('casts amount_billed to decimal', function () {
    $budget = ProjectBudget::factory()->create(['amount_billed' => 10000.00]);

    expect($budget->amount_billed)->toBeFloat();
});

test('casts start_date to date', function () {
    $budget = ProjectBudget::factory()->create(['start_date' => '2025-01-01']);

    expect($budget->start_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts end_date to date', function () {
    $budget = ProjectBudget::factory()->create(['end_date' => '2025-12-31']);

    expect($budget->end_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts last_activity_at to datetime', function () {
    $budget = ProjectBudget::factory()->create(['last_activity_at' => now()]);

    expect($budget->last_activity_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to project relationship', function () {
    $budget = ProjectBudget::factory()->create();

    expect($budget->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getBudgetUsedPercentAttribute calculates correctly', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 50]);

    expect($budget->budget_used_percent)->toBe(50.0);
});

test('getBudgetUsedPercentAttribute returns zero when no budget', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => null, 'hours_logged' => 50]);

    expect($budget->budget_used_percent)->toBe(0.0);
});

test('getRemainingHoursAttribute calculates correctly', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 60]);

    expect($budget->remaining_hours)->toBe(40.0);
});

test('getRemainingHoursAttribute returns zero when over budget', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 120]);

    expect($budget->remaining_hours)->toBe(0.0);
});

test('isOverBudget returns true when hours exceeded', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 110]);

    expect($budget->isOverBudget())->toBeTrue();
});

test('isOverBudget returns false when within budget', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 80]);

    expect($budget->isOverBudget())->toBeFalse();
});

test('isAtRisk returns true when usage at 80 percent', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 85]);

    expect($budget->isAtRisk())->toBeTrue();
});

test('isAtRisk returns false when below 80 percent', function () {
    $budget = ProjectBudget::factory()->create(['budget_hours' => 100, 'hours_logged' => 70]);

    expect($budget->isAtRisk())->toBeFalse();
});

test('can be created via factory', function () {
    $budget = ProjectBudget::factory()->create();

    expect($budget)->toBeInstanceOf(ProjectBudget::class)
        ->and($budget->exists)->toBeTrue();
});
