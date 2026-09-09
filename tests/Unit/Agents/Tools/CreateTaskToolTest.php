<?php

use App\Agents\Tools\CreateTaskTool;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new CreateTaskTool;
    expect($tool->name())->toBe('Create Task');
});

test('getDescription returns correct description', function () {
    $tool = new CreateTaskTool;
    expect($tool->description())->toContain('Create a new task');
});

test('requiresApproval returns true', function () {
    $tool = new CreateTaskTool;
    expect($tool->requiresApproval())->toBeTrue();
});

test('riskLevel returns medium', function () {
    $tool = new CreateTaskTool;
    expect($tool->riskLevel())->toBe('medium');
});

test('getParameters includes required title', function () {
    $tool = new CreateTaskTool;
    $schema = $tool->inputSchema();

    expect($schema['required'])->toContain('title')
        ->and($schema['properties'])->toHaveKey('title');
});

test('execute creates task with minimal params', function () {
    $tool = new CreateTaskTool;
    $result = $tool->execute(['title' => 'New Task']);

    expect($result)->toHaveKey('created')
        ->and($result['created'])->toBeTrue()
        ->and($result)->toHaveKey('task')
        ->and($result['task']['title'])->toBe('New Task');

    $this->assertDatabaseHas('tasks', ['title' => 'New Task']);
});

test('execute creates task with all params', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $tool = new CreateTaskTool;
    $result = $tool->execute([
        'title' => 'Complete Task',
        'description' => 'Task description',
        'project_id' => $project->id,
        'assignee_id' => $user->id,
        'priority' => 'high',
        'due_date' => '2025-12-31',
    ]);

    expect($result['created'])->toBeTrue();

    $this->assertDatabaseHas('tasks', [
        'title' => 'Complete Task',
        'description' => 'Task description',
        'project_id' => $project->id,
        'assignee_id' => $user->id,
        'priority' => 'high',
        'status' => 'pending',
        'source' => 'ai',
    ]);
});

test('execute resolves project by name', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);

    $tool = new CreateTaskTool;
    $result = $tool->execute([
        'title' => 'Task',
        'project_name' => 'Test Project',
    ]);

    expect($result['task']['project'])->toBe('Test Project');
    $this->assertDatabaseHas('tasks', ['project_id' => $project->id]);
});

test('execute resolves assignee by name', function () {
    $user = User::factory()->create(['name' => 'John Doe']);

    $tool = new CreateTaskTool;
    $result = $tool->execute([
        'title' => 'Task',
        'assignee_name' => 'John',
    ]);

    expect($result['task']['assignee'])->toBe('John Doe');
    $this->assertDatabaseHas('tasks', ['assignee_id' => $user->id]);
});

test('execute sets default priority to medium', function () {
    $tool = new CreateTaskTool;
    $tool->execute(['title' => 'Task']);

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task',
        'priority' => 'medium',
    ]);
});

test('execute sets status to pending', function () {
    $tool = new CreateTaskTool;
    $tool->execute(['title' => 'Task']);

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task',
        'status' => 'pending',
    ]);
});

test('execute sets source to ai', function () {
    $tool = new CreateTaskTool;
    $tool->execute(['title' => 'Task']);

    $this->assertDatabaseHas('tasks', [
        'title' => 'Task',
        'source' => 'ai',
    ]);
});

test('validate rejects missing title', function () {
    $tool = new CreateTaskTool;
    $tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid priority', function () {
    $tool = new CreateTaskTool;
    $tool->validate([
        'title' => 'Task',
        'priority' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid project_id', function () {
    $tool = new CreateTaskTool;
    $tool->validate([
        'title' => 'Task',
        'project_id' => 99999,
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid assignee_id', function () {
    $tool = new CreateTaskTool;
    $tool->validate([
        'title' => 'Task',
        'assignee_id' => 99999,
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid date format', function () {
    $tool = new CreateTaskTool;
    $tool->validate([
        'title' => 'Task',
        'due_date' => 'not-a-date',
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $tool = new CreateTaskTool;
    $validated = $tool->validate([
        'title' => 'Task',
        'description' => 'Description',
        'project_id' => $project->id,
        'assignee_id' => $user->id,
        'priority' => 'high',
        'due_date' => '2025-12-31',
    ]);

    expect($validated)->toHaveKeys(['title', 'description', 'project_id', 'assignee_id', 'priority', 'due_date']);
});
