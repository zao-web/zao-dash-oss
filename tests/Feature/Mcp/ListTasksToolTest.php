<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create();
});

test('lists all tasks', function () {
    Task::factory()->count(3)->create(['project_id' => $this->project->id]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListTasksTool::class, []);

    $response->assertOk();
});

test('filters unassigned tasks', function () {
    $assignedTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'assigned_to' => $this->user->id,
        'title' => 'Assigned Task',
    ]);

    $unassignedTask = Task::factory()->create([
        'project_id' => $this->project->id,
        'assigned_to' => null,
        'title' => 'Unassigned Task',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListTasksTool::class, [
        'unassigned' => true,
    ]);

    $response->assertOk();
    $response->assertSee('Unassigned Task');
    $response->assertDontSee('Assigned Task');
});

test('filters by assignee id', function () {
    $anotherUser = User::factory()->create();

    Task::factory()->create([
        'project_id' => $this->project->id,
        'assigned_to' => $this->user->id,
        'title' => 'My Task',
    ]);

    Task::factory()->create([
        'project_id' => $this->project->id,
        'assigned_to' => $anotherUser->id,
        'title' => 'Their Task',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListTasksTool::class, [
        'assignee_id' => $this->user->id,
    ]);

    $response->assertOk();
    $response->assertSee('My Task');
    $response->assertDontSee('Their Task');
});
