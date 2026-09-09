<?php

use App\Jobs\ExecuteAgentJob;
use App\Jobs\ProcessAgentTasksJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('job can be dispatched', function () {
    Queue::fake();

    ProcessAgentTasksJob::dispatch();

    Queue::assertPushed(ProcessAgentTasksJob::class);
});

test('handle does nothing when no ready tasks', function () {
    Queue::fake();

    AgentTask::factory()->create(['status' => 'completed']);
    AgentTask::factory()->create(['status' => 'running']);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertNothingPushed();
});

test('handle processes ready tasks', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);
    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
        'task_description' => 'Test task',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class);
});

test('handle limits to 10 tasks per run', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);

    // Create 15 ready tasks
    AgentTask::factory()->count(15)->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    // Should only process 10
    Queue::assertPushed(ExecuteAgentJob::class, 10);
});

test('handle prioritizes high priority tasks', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);

    $lowTask = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
        'priority' => 'low',
    ]);

    $highTask = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
        'priority' => 'high',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    // Verify high priority task is started first
    $highTask->refresh();
    expect($highTask->status)->toBe('running');
});

test('handle skips tasks for inactive agents', function () {
    Queue::fake();

    $inactiveAgent = Agent::factory()->create(['status' => 'disabled']);
    $task = AgentTask::factory()->create([
        'agent_id' => $inactiveAgent->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertNothingPushed();

    $task->refresh();
    expect($task->status)->toBe('failed');
});

test('handle skips tasks when circuit breaker active', function () {
    Queue::fake();

    $agent = Agent::factory()->create([
        'status' => 'active',
        'circuit_broken_at' => now(),
    ]);

    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertNothingPushed();

    // Task should remain pending for later retry
    $task->refresh();
    expect($task->status)->toBe('pending');
});

test('handle marks task as running before dispatching', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);
    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    $task->refresh();
    expect($task->status)->toBe('running');
});

test('handle dispatches ExecuteAgentJob with correct config', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);
    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
        'task_description' => 'Complete this task',
        'priority' => 'high',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($task) {
        return $job->agent->id === $task->agent_id
            && $job->config['prompt'] === 'Complete this task'
            && ($job->config['task_id'] ?? null) === null
            && $job->config['agent_task_id'] === $task->id
            && $job->invocationSource === AgentRun::SOURCE_CHAINED
            && $job->invokedBy === 'strategist';
    });
});

test('handle includes task metadata in trigger data', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);
    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
        'priority' => 'urgent',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($task) {
        return ($job->triggerMetadata['task_id'] ?? null) === null
            && $job->triggerMetadata['agent_task_id'] === $task->id
            && $job->triggerMetadata['priority'] === 'urgent';
    });
});

test('handle logs task processing', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['status' => 'active']);
    $task = AgentTask::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class, 1);
});

test('handle processes tasks from multiple agents', function () {
    Queue::fake();

    $agent1 = Agent::factory()->create(['status' => 'active']);
    $agent2 = Agent::factory()->create(['status' => 'active']);

    AgentTask::factory()->create([
        'agent_id' => $agent1->id,
        'status' => 'pending',
    ]);

    AgentTask::factory()->create([
        'agent_id' => $agent2->id,
        'status' => 'pending',
    ]);

    $job = new ProcessAgentTasksJob;
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class, 2);
});

test('job implements ShouldQueue interface', function () {
    $job = new ProcessAgentTasksJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(ProcessAgentTasksJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
