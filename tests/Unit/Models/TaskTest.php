<?php

use App\Models\Task;
use Illuminate\Database\Eloquent\SoftDeletes;

test('uses soft deletes', function () {
    expect(in_array(SoftDeletes::class, class_uses(Task::class)))->toBeTrue();
});

test('has guarded attributes empty', function () {
    expect((new Task)->getGuarded())->toBe(['*']);
});

test('casts due_date to date', function () {
    $task = Task::factory()->create(['due_date' => '2025-12-31']);

    expect($task->due_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to project relationship', function () {
    $task = Task::factory()->create();

    expect($task->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to assignee relationship', function () {
    $task = Task::factory()->create();

    expect($task->assignee())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to milestone relationship', function () {
    $task = Task::factory()->create();

    expect($task->milestone())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be soft deleted', function () {
    $task = Task::factory()->create();
    $task->delete();

    expect($task->trashed())->toBeTrue()
        ->and(Task::withTrashed()->find($task->id))->not->toBeNull();
});

test('can be created via factory', function () {
    $task = Task::factory()->create();

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->exists)->toBeTrue();
});
