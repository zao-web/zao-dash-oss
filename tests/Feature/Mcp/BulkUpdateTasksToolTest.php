<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\BulkUpdateTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
});

test('bulk updates task assignee', function () {
    $tasks = Task::factory()->count(3)->create([
        'project_id' => $this->project->id,
        'assigned_to' => null,
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(BulkUpdateTasksTool::class, [
        'task_ids' => $tasks->pluck('id')->toArray(),
        'assignee_id' => $this->user->id,
    ]);

    $response->assertOk();
    $response->assertSee('Updated 3 task(s) successfully');

    foreach ($tasks as $task) {
        expect($task->fresh()->assigned_to)->toBe($this->user->id);
    }
});

test('bulk updates task status', function () {
    $tasks = Task::factory()->count(2)->create([
        'project_id' => $this->project->id,
        'status' => 'pending',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(BulkUpdateTasksTool::class, [
        'task_ids' => $tasks->pluck('id')->toArray(),
        'status' => 'in_progress',
    ]);

    $response->assertOk();

    foreach ($tasks as $task) {
        expect($task->fresh()->status)->toBe('in_progress');
    }
});

test('requires at least one field to update', function () {
    $tasks = Task::factory()->count(2)->create([
        'project_id' => $this->project->id,
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(BulkUpdateTasksTool::class, [
        'task_ids' => $tasks->pluck('id')->toArray(),
    ]);

    $response->assertSee('No fields to update provided');
});

test('validates task ids exist', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(BulkUpdateTasksTool::class, [
        'task_ids' => [99999],
        'status' => 'completed',
    ]);

    $response->assertHasErrors();
});
