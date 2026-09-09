<?php

use App\Models\Agent;
use App\Models\AgentTask;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new AgentTask)->getGuarded())->toBe([]);
});

test('casts context to array', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'context' => ['key' => 'value'],
        'status' => 'pending',
    ]);

    expect($task->context)->toBeArray()
        ->and($task->context)->toBe(['key' => 'value']);
});

test('casts result to array', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'result' => ['output' => 'success'],
        'status' => 'pending',
    ]);

    expect($task->result)->toBeArray()
        ->and($task->result)->toBe(['output' => 'success']);
});

test('casts scheduled_for to datetime', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'scheduled_for' => now(),
        'status' => 'pending',
    ]);

    expect($task->scheduled_for)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts started_at to datetime', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'started_at' => now(),
        'status' => 'pending',
    ]);

    expect($task->started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts completed_at to datetime', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'completed_at' => now(),
        'status' => 'pending',
    ]);

    expect($task->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to agent relationship', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'status' => 'pending',
    ]);

    expect($task->agent())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to assigned by relationship', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'status' => 'pending',
    ]);

    expect($task->assignedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to weekly plan item relationship', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'status' => 'pending',
    ]);

    expect($task->weeklyPlanItem())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to agent run relationship', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Test task',
        'status' => 'pending',
    ]);

    expect($task->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('ready scope returns pending tasks without schedule', function () {
    $agent = Agent::factory()->create();

    $readyTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Ready task',
        'status' => AgentTask::STATUS_PENDING,
    ]);

    $scheduledTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Scheduled task',
        'status' => AgentTask::STATUS_PENDING,
        'scheduled_for' => now()->addDays(2),
    ]);

    $results = AgentTask::ready()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($readyTask->id);
});

test('ready scope returns pending tasks scheduled for past', function () {
    $agent = Agent::factory()->create();

    $pastScheduledTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Past scheduled task',
        'status' => AgentTask::STATUS_PENDING,
        'scheduled_for' => now()->subDays(1),
    ]);

    $results = AgentTask::ready()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($pastScheduledTask->id);
});

test('scheduled scope returns future scheduled tasks', function () {
    $agent = Agent::factory()->create();

    $futureTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Future task',
        'status' => AgentTask::STATUS_SCHEDULED,
        'scheduled_for' => now()->addDays(2),
    ]);

    $pastTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Past task',
        'status' => AgentTask::STATUS_SCHEDULED,
        'scheduled_for' => now()->subDays(1),
    ]);

    $results = AgentTask::scheduled()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($futureTask->id);
});

test('byPriority scope orders by priority correctly', function () {
    $agent = Agent::factory()->create();

    $lowTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Low priority',
        'status' => 'pending',
        'priority' => AgentTask::PRIORITY_LOW,
    ]);

    $urgentTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Urgent priority',
        'status' => 'pending',
        'priority' => AgentTask::PRIORITY_URGENT,
    ]);

    $normalTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Normal priority',
        'status' => 'pending',
        'priority' => AgentTask::PRIORITY_NORMAL,
    ]);

    // SQLite doesn't support FIELD function, so just verify the scope exists
    $results = AgentTask::byPriority()->get();

    expect($results)->toHaveCount(3);
})->skip('FIELD function not supported in SQLite');

test('is_ready accessor returns true for pending task without schedule', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Ready task',
        'status' => AgentTask::STATUS_PENDING,
    ]);

    expect($task->is_ready)->toBeTrue();
});

test('is_ready accessor returns false for scheduled future task', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Future task',
        'status' => AgentTask::STATUS_PENDING,
        'scheduled_for' => now()->addDays(1),
    ]);

    expect($task->is_ready)->toBeFalse();
});

test('is_ready accessor returns false for running task', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Running task',
        'status' => AgentTask::STATUS_RUNNING,
    ]);

    expect($task->is_ready)->toBeFalse();
});

test('scheduleFor method sets status and scheduled_for', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Task to schedule',
        'status' => AgentTask::STATUS_PENDING,
    ]);

    $scheduleTime = now()->addDays(1);
    $task->scheduleFor($scheduleTime);

    expect($task->fresh()->status)->toBe(AgentTask::STATUS_SCHEDULED)
        ->and($task->fresh()->scheduled_for->toDateTimeString())->toBe($scheduleTime->toDateTimeString());
});

test('start method sets status and started_at', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Task to start',
        'status' => AgentTask::STATUS_PENDING,
    ]);

    $task->start();

    expect($task->fresh()->status)->toBe(AgentTask::STATUS_RUNNING)
        ->and($task->fresh()->started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('complete method sets status and result', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Task to complete',
        'status' => AgentTask::STATUS_RUNNING,
    ]);

    $result = ['output' => 'success', 'data' => 'value'];
    $task->complete($result);

    expect($task->fresh()->status)->toBe(AgentTask::STATUS_COMPLETED)
        ->and($task->fresh()->result)->toBe($result)
        ->and($task->fresh()->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('fail method sets status and error result', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Task to fail',
        'status' => AgentTask::STATUS_RUNNING,
    ]);

    $error = 'Something went wrong';
    $task->fail($error);

    expect($task->fresh()->status)->toBe(AgentTask::STATUS_FAILED)
        ->and($task->fresh()->result['error'])->toBe($error)
        ->and($task->fresh()->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('queueRetry method sets retry metadata and status', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Task to retry',
        'status' => AgentTask::STATUS_RUNNING,
    ]);

    $task->queueRetry(2, 15000, 'Temporary failure');

    expect($task->fresh()->status)->toBe(AgentTask::STATUS_RETRY_QUEUED)
        ->and($task->fresh()->retry_attempt)->toBe(2)
        ->and($task->fresh()->last_error)->toBe('Temporary failure')
        ->and($task->fresh()->retry_due_at)->not->toBeNull();
});

test('ready scope includes retry queued tasks that are due', function () {
    $agent = Agent::factory()->create();

    $dueTask = AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Due retry',
        'status' => AgentTask::STATUS_RETRY_QUEUED,
        'retry_due_at' => now()->subSecond(),
    ]);

    AgentTask::create([
        'agent_id' => $agent->id,
        'task_description' => 'Future retry',
        'status' => AgentTask::STATUS_RETRY_QUEUED,
        'retry_due_at' => now()->addMinute(),
    ]);

    $results = AgentTask::ready()->pluck('id');

    expect($results)->toContain($dueTask->id);
});

test('createFromStrategist creates task with correct attributes', function () {
    $agent = Agent::factory()->create();
    $description = 'Test task from strategist';
    $context = ['goal' => 'test'];

    $task = AgentTask::createFromStrategist(
        $agent,
        $description,
        $context,
        AgentTask::PRIORITY_HIGH
    );

    expect($task->agent_id)->toBe($agent->id)
        ->and($task->task_description)->toBe($description)
        ->and($task->context)->toBe($context)
        ->and($task->priority)->toBe(AgentTask::PRIORITY_HIGH)
        ->and($task->status)->toBe(AgentTask::STATUS_PENDING);
});

test('createFromStrategist creates scheduled task when date provided', function () {
    $agent = Agent::factory()->create();
    $scheduledFor = now()->addDays(3);

    $task = AgentTask::createFromStrategist(
        $agent,
        'Scheduled task',
        [],
        'normal',
        $scheduledFor
    );

    expect($task->status)->toBe(AgentTask::STATUS_SCHEDULED)
        ->and($task->scheduled_for->toDateTimeString())->toBe($scheduledFor->toDateTimeString());
});

test('can be created directly', function () {
    $task = AgentTask::create([
        'agent_id' => Agent::factory()->create()->id,
        'task_description' => 'Direct creation test',
        'status' => 'pending',
    ]);

    expect($task)->toBeInstanceOf(AgentTask::class)
        ->and($task->exists)->toBeTrue();
});
