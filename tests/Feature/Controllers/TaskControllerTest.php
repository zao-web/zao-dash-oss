<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create task', function () {
    $project = Project::factory()->create();

    $response = $this->post(route('tasks.store'), [
        'title' => 'Test Task',
        'description' => 'Test Description',
        'status' => 'pending',
        'priority' => 'high',
        'project_id' => $project->id,
        'assigned_to' => $this->user->id,
        'due_date' => now()->addDays(3)->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task created successfully.');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Test Task',
        'status' => 'pending',
        'priority' => 'high',
        'project_id' => $project->id,
    ]);
});

test('task creation requires title', function () {
    $response = $this->post(route('tasks.store'), [
        'status' => 'pending',
    ]);

    $response->assertSessionHasErrors(['title']);
});

test('task status must be valid', function () {
    $response = $this->post(route('tasks.store'), [
        'title' => 'Test Task',
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('task priority must be valid', function () {
    $response = $this->post(route('tasks.store'), [
        'title' => 'Test Task',
        'priority' => 'invalid-priority',
    ]);

    $response->assertSessionHasErrors(['priority']);
});

test('task source must be valid', function () {
    $response = $this->post(route('tasks.store'), [
        'title' => 'Test Task',
        'source' => 'invalid-source',
    ]);

    $response->assertSessionHasErrors(['source']);
});

test('can update task', function () {
    $task = Task::factory()->create([
        'title' => 'Old Title',
        'status' => 'pending',
    ]);

    $response = $this->put(route('tasks.update', $task), [
        'title' => 'New Title',
        'description' => 'Updated Description',
        'status' => 'in_progress',
        'priority' => 'urgent',
        'due_date' => now()->addDays(1)->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task updated successfully.');

    $task->refresh();
    expect($task->title)->toBe('New Title');
    expect($task->status)->toBe('in_progress');
    expect($task->priority)->toBe('urgent');
});

test('can delete task', function () {
    $task = Task::factory()->create();

    $response = $this->delete(route('tasks.destroy', $task));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task deleted successfully.');

    $this->assertDatabaseMissing('tasks', [
        'id' => $task->id,
    ]);
});

test('can update task status', function () {
    $task = Task::factory()->create(['status' => 'pending']);

    $response = $this->put(route('tasks.updateStatus', $task), [
        'status' => 'completed',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task status updated successfully.');

    $task->refresh();
    expect($task->status)->toBe('completed');
});

test('can update task status with position', function () {
    $task = Task::factory()->create(['status' => 'pending']);

    $response = $this->put(route('tasks.updateStatus', $task), [
        'status' => 'in_progress',
        'position' => 5,
    ]);

    $response->assertRedirect();

    $task->refresh();
    expect($task->status)->toBe('in_progress');
    expect($task->position)->toBe(5);
});

test('can reorder tasks', function () {
    $task1 = Task::factory()->create();
    $task2 = Task::factory()->create();
    $task3 = Task::factory()->create();

    $response = $this->post(route('tasks.reorder'), [
        'tasks' => [
            ['id' => $task1->id, 'position' => 2, 'status' => 'pending'],
            ['id' => $task2->id, 'position' => 0, 'status' => 'in_progress'],
            ['id' => $task3->id, 'position' => 1, 'status' => 'pending'],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Tasks reordered successfully.');

    $task1->refresh();
    $task2->refresh();
    expect($task1->position)->toBe(2);
    expect($task2->position)->toBe(0);
    expect($task2->status)->toBe('in_progress');
});

test('can assign task to user', function () {
    $task = Task::factory()->create(['assigned_to' => null]);
    $assignee = User::factory()->create();

    $response = $this->put(route('tasks.assign', $task), [
        'assigned_to' => $assignee->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task assigned successfully.');

    $task->refresh();
    expect($task->assigned_to)->toBe($assignee->id);
});

test('can unassign task', function () {
    $task = Task::factory()->create(['assigned_to' => $this->user->id]);

    $response = $this->put(route('tasks.assign', $task), [
        'assigned_to' => null,
    ]);

    $response->assertRedirect();

    $task->refresh();
    expect($task->assigned_to)->toBeNull();
});

test('assigned_to must be valid user', function () {
    $task = Task::factory()->create();

    $response = $this->put(route('tasks.assign', $task), [
        'assigned_to' => 99999,
    ]);

    $response->assertSessionHasErrors(['assigned_to']);
});

test('can update task priority', function () {
    $task = Task::factory()->create(['priority' => 'low']);

    $response = $this->post(route('tasks.updatePriority', $task), [
        'priority' => 'urgent',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Task priority updated successfully.');

    $task->refresh();
    expect($task->priority)->toBe('urgent');
});

test('task priority update must be valid', function () {
    $task = Task::factory()->create(['priority' => 'low']);

    $response = $this->post(route('tasks.updatePriority', $task), [
        'priority' => 'invalid',
    ]);

    $response->assertSessionHasErrors(['priority']);
});

test('task priority update requires priority field', function () {
    $task = Task::factory()->create(['priority' => 'low']);

    $response = $this->post(route('tasks.updatePriority', $task), []);

    $response->assertSessionHasErrors(['priority']);
});
