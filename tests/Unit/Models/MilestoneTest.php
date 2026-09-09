<?php

use App\Models\Milestone;

test('has guarded attributes empty', function () {
    expect((new Milestone)->getGuarded())->toBe(['*']);
});

test('casts due_date to date', function () {
    $milestone = Milestone::factory()->create(['due_date' => '2025-12-31']);

    expect($milestone->due_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts completed_at to datetime', function () {
    $milestone = Milestone::factory()->create(['completed_at' => now()]);

    expect($milestone->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to project relationship', function () {
    $milestone = Milestone::factory()->create();

    expect($milestone->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be created via factory', function () {
    $milestone = Milestone::factory()->create();

    expect($milestone)->toBeInstanceOf(Milestone::class)
        ->and($milestone->exists)->toBeTrue();
});
