<?php

use App\Models\Project;
use Illuminate\Database\Eloquent\SoftDeletes;

test('uses soft deletes', function () {
    expect(in_array(SoftDeletes::class, class_uses(Project::class)))->toBeTrue();
});

test('has guarded attributes empty', function () {
    expect((new Project)->getGuarded())->toBe(['*']);
});

test('casts budget to decimal', function () {
    $project = Project::factory()->create(['budget' => 1500.50]);

    expect($project->budget)->toBeFloat()
        ->and((string) $project->budget)->toBe('1500.50');
});

test('route key name is slug', function () {
    $project = new Project;

    expect($project->getRouteKeyName())->toBe('slug');
});

test('belongs to client relationship', function () {
    $project = Project::factory()->create();

    expect($project->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many milestones relationship', function () {
    $project = Project::factory()->create();

    expect($project->milestones())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many tasks relationship', function () {
    $project = Project::factory()->create();

    expect($project->tasks())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be soft deleted', function () {
    $project = Project::factory()->create();
    $project->delete();

    expect($project->trashed())->toBeTrue()
        ->and(Project::withTrashed()->find($project->id))->not->toBeNull();
});

test('can be created via factory', function () {
    $project = Project::factory()->create();

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->exists)->toBeTrue();
});
