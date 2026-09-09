<?php

use App\Jobs\PushTaskStatusToSheetJob;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([PushTaskStatusToSheetJob::class]);
});

it('stamps completed_at when a task transitions into completed', function () {
    $task = Task::factory()->inProgress()->create(['completed_at' => null]);

    $task->update(['status' => 'completed']);

    expect($task->fresh()->completed_at)->not->toBeNull();
});

it('clears completed_at when a completed task is reopened', function () {
    $task = Task::factory()->completed()->create(['completed_at' => now()]);

    $task->update(['status' => 'in_progress']);

    expect($task->fresh()->completed_at)->toBeNull();
});

it('does not overwrite an existing completed_at on an unrelated save', function () {
    $stampedAt = now()->subDays(3)->startOfSecond();
    $task = Task::factory()->completed()->create(['completed_at' => $stampedAt]);

    $task->update(['title' => 'Renamed, still completed']);

    expect($task->fresh()->completed_at->equalTo($stampedAt))->toBeTrue();
});

it('does not stamp completed_at for a non-completed status change', function () {
    $task = Task::factory()->pending()->create(['completed_at' => null]);

    $task->update(['status' => 'in_progress']);

    expect($task->fresh()->completed_at)->toBeNull();
});

it('respects an explicit completed_at provided at creation', function () {
    $explicit = now()->subDays(10)->startOfSecond();
    $task = Task::factory()->completed()->create(['completed_at' => $explicit]);

    expect($task->fresh()->completed_at->equalTo($explicit))->toBeTrue();
});
