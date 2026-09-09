<?php

use App\Agents\Tools\UpdateTaskTool;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new UpdateTaskTool;
    expect($tool->name())->toBe('Update Task');
});

test('getDescription returns correct description', function () {
    $tool = new UpdateTaskTool;
    expect($tool->description())->toContain('Update a task');
});

test('requiresApproval returns true', function () {
    $tool = new UpdateTaskTool;
    expect($tool->requiresApproval())->toBeTrue();
});

test('riskLevel returns low', function () {
    $tool = new UpdateTaskTool;
    expect($tool->riskLevel())->toBe('low');
});

test('execute updates task by ID', function () {
    $task = Task::factory()->create(['status' => 'pending']);

    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_id' => $task->id,
        'status' => 'completed',
    ]);

    expect($result['updated'])->toBeTrue();
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'completed',
    ]);
});

test('execute updates task by title', function () {
    $task = Task::factory()->create([
        'title' => 'Fix Bug #123',
        'priority' => 'medium',
    ]);

    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_title' => 'Bug #123',
        'priority' => 'urgent',
    ]);

    expect($result['updated'])->toBeTrue();
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'priority' => 'urgent',
    ]);
});

test('execute updates status', function () {
    $task = Task::factory()->create(['status' => 'pending']);

    $tool = new UpdateTaskTool;
    $tool->execute([
        'task_id' => $task->id,
        'status' => 'in_progress',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'in_progress',
    ]);
});

test('execute updates priority', function () {
    $task = Task::factory()->create(['priority' => 'low']);

    $tool = new UpdateTaskTool;
    $tool->execute([
        'task_id' => $task->id,
        'priority' => 'high',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'priority' => 'high',
    ]);
});

test('execute updates due_date', function () {
    $task = Task::factory()->create(['due_date' => null]);

    $tool = new UpdateTaskTool;
    $tool->execute([
        'task_id' => $task->id,
        'due_date' => '2025-12-31',
    ]);

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'due_date' => '2025-12-31',
    ]);
});

test('execute updates multiple fields', function () {
    $task = Task::factory()->create([
        'status' => 'pending',
        'priority' => 'low',
    ]);

    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_id' => $task->id,
        'status' => 'completed',
        'priority' => 'high',
        'due_date' => '2025-12-31',
    ]);

    expect($result['updated'])->toBeTrue();
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'completed',
        'priority' => 'high',
        'due_date' => '2025-12-31',
    ]);
});

test('execute returns error when task not found by ID', function () {
    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_id' => 99999,
        'status' => 'completed',
    ]);

    expect($result['updated'])->toBeFalse()
        ->and($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not found');
});

test('execute returns error when task not found by title', function () {
    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_title' => 'Nonexistent Task',
        'status' => 'completed',
    ]);

    expect($result['updated'])->toBeFalse()
        ->and($result['error'])->toContain('not found');
});

test('execute returns error when no updates provided', function () {
    $task = Task::factory()->create();

    $tool = new UpdateTaskTool;
    $result = $tool->execute(['task_id' => $task->id]);

    expect($result['updated'])->toBeFalse()
        ->and($result['error'])->toContain('No updates');
});

test('execute returns updated task data', function () {
    $task = Task::factory()->create([
        'title' => 'Test Task',
        'status' => 'pending',
    ]);

    $tool = new UpdateTaskTool;
    $result = $tool->execute([
        'task_id' => $task->id,
        'status' => 'completed',
    ]);

    expect($result['task'])->toHaveKeys(['id', 'title', 'status', 'priority', 'due_date'])
        ->and($result['task']['id'])->toBe($task->id)
        ->and($result['task']['title'])->toBe('Test Task')
        ->and($result['task']['status'])->toBe('completed');
});

test('validate rejects invalid task_id', function () {
    $tool = new UpdateTaskTool;
    $tool->validate(['task_id' => 99999]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid status', function () {
    $task = Task::factory()->create();

    $tool = new UpdateTaskTool;
    $tool->validate([
        'task_id' => $task->id,
        'status' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid priority', function () {
    $task = Task::factory()->create();

    $tool = new UpdateTaskTool;
    $tool->validate([
        'task_id' => $task->id,
        'priority' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $task = Task::factory()->create();

    $tool = new UpdateTaskTool;
    $validated = $tool->validate([
        'task_id' => $task->id,
        'status' => 'completed',
        'priority' => 'high',
        'due_date' => '2025-12-31',
    ]);

    expect($validated)->toHaveKeys(['task_id', 'status', 'priority', 'due_date']);
});
