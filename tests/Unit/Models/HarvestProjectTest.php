<?php

use App\Models\HarvestProject;

test('has guarded attributes empty', function () {
    expect((new HarvestProject)->getGuarded())->toBe(['*']);
});

test('casts is_active to boolean', function () {
    $project = HarvestProject::factory()->create(['is_active' => true]);

    expect($project->is_active)->toBeTrue();
});

test('casts is_billable to boolean', function () {
    $project = HarvestProject::factory()->create(['is_billable' => false]);

    expect($project->is_billable)->toBeFalse();
});

test('casts hourly_rate to decimal', function () {
    $project = HarvestProject::factory()->create(['hourly_rate' => 125.50]);

    expect($project->hourly_rate)->toBeFloat()
        ->and((string) $project->hourly_rate)->toBe('125.50');
});

test('casts budget to decimal', function () {
    $project = HarvestProject::factory()->create(['budget' => 10000.00]);

    expect($project->budget)->toBeFloat();
});

test('casts budget_is_monthly to boolean', function () {
    $project = HarvestProject::factory()->create(['budget_is_monthly' => true]);

    expect($project->budget_is_monthly)->toBeTrue();
});

test('belongs to client relationship', function () {
    $project = HarvestProject::factory()->create();

    expect($project->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $project = HarvestProject::factory()->create();

    expect($project->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many time entries relationship', function () {
    $project = HarvestProject::factory()->create();

    expect($project->timeEntries())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be created via factory', function () {
    $project = HarvestProject::factory()->create();

    expect($project)->toBeInstanceOf(HarvestProject::class)
        ->and($project->exists)->toBeTrue();
});
