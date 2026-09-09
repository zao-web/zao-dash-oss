<?php

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ClientContact;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a user to a task', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $assignee->id,
            'assignee_type' => 'user',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->assigned_to)->toBe($assignee->id);
    expect($task->assignee_type)->toBe('user');
});

it('assigns an agent to a task', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->assigned_to)->toBe($agent->id);
    expect($task->assignee_type)->toBe('agent');
});

it('assigns a client contact to a task', function () {
    $user = User::factory()->create();
    $contact = ClientContact::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $contact->id,
            'assignee_type' => 'client_contact',
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->assigned_to)->toBe($contact->id);
    expect($task->assignee_type)->toBe('client_contact');
});

it('unassigns a task by setting assigned_to to null', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create();
    $task = Task::factory()->create([
        'assigned_to' => $assignee->id,
        'assignee_type' => 'user',
    ]);

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => null,
            'assignee_type' => null,
        ])
        ->assertRedirect();

    $task->refresh();
    expect($task->assigned_to)->toBeNull();
    expect($task->assignee_type)->toBeNull();
});

it('logs an assignment comment when assigning to user', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $assignee->id,
            'assignee_type' => 'user',
        ]);

    $comment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_ASSIGNMENT)
        ->first();

    expect($comment)->not->toBeNull();
    expect($comment->content)->toContain($assignee->name);
    expect($comment->metadata['new_assignee_type'])->toBe('user');
});

it('logs an assignment comment when assigning to agent', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
        ]);

    $comment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_ASSIGNMENT)
        ->first();

    expect($comment)->not->toBeNull();
    expect($comment->content)->toContain($agent->name);
    expect($comment->metadata['new_assignee_type'])->toBe('agent');
});

it('creates an AgentTask when assigning an agent', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
        ]);

    $agentTask = AgentTask::where('task_id', $task->id)
        ->where('agent_id', $agent->id)
        ->first();

    expect($agentTask)->not->toBeNull();
    expect($agentTask->status)->toBe(AgentTask::STATUS_PENDING);
    expect($agentTask->assigned_by)->toBe($user->id);
});

it('does not create an AgentTask when assigning a user', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => $assignee->id,
            'assignee_type' => 'user',
        ]);

    expect(AgentTask::where('task_id', $task->id)->count())->toBe(0);
});

it('rejects invalid assignee_type values', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => 1,
            'assignee_type' => 'invalid_type',
        ])
        ->assertSessionHasErrors('assignee_type');
});

it('rejects non-existent assignee ID for agent type', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->post("/tasks/{$task->id}/assign", [
            'assigned_to' => 99999,
            'assignee_type' => 'agent',
        ])
        ->assertSessionHasErrors('assigned_to');
});

it('resolves assignee info for user type', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create([
        'assigned_to' => $user->id,
        'assignee_type' => 'user',
    ]);

    $info = $task->assignee_info;
    expect($info)->not->toBeNull();
    expect($info['id'])->toBe($user->id);
    expect($info['name'])->toBe($user->name);
    expect($info['type'])->toBe('user');
});

it('resolves assignee info for agent type', function () {
    $agent = Agent::factory()->create();
    $task = Task::factory()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $info = $task->assignee_info;
    expect($info)->not->toBeNull();
    expect($info['id'])->toBe($agent->id);
    expect($info['name'])->toBe($agent->name);
    expect($info['type'])->toBe('agent');
});

it('resolves assignee info for client_contact type', function () {
    $contact = ClientContact::factory()->create();
    $task = Task::factory()->create([
        'assigned_to' => $contact->id,
        'assignee_type' => 'client_contact',
    ]);

    $info = $task->assignee_info;
    expect($info)->not->toBeNull();
    expect($info['id'])->toBe($contact->id);
    expect($info['name'])->toBe($contact->name);
    expect($info['type'])->toBe('client_contact');
});

it('returns null assignee info when unassigned', function () {
    $task = Task::factory()->create([
        'assigned_to' => null,
        'assignee_type' => null,
    ]);

    expect($task->assignee_info)->toBeNull();
});

it('creates a task with agent assignee via store endpoint', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create();

    $this->actingAs($user)
        ->post('/tasks', [
            'title' => 'Agent-assigned task',
            'assigned_to' => $agent->id,
            'assignee_type' => 'agent',
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Agent-assigned task')->first();
    expect($task)->not->toBeNull();
    expect($task->assigned_to)->toBe($agent->id);
    expect($task->assignee_type)->toBe('agent');
});

it('defaults assignee_type to user when not specified', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create();

    $this->actingAs($user)
        ->post('/tasks', [
            'title' => 'Default type task',
            'assigned_to' => $assignee->id,
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Default type task')->first();
    expect($task->assignee_type)->toBe('user');
});
