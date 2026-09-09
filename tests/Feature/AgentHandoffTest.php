<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Agents\AgentExecutor;
use App\Services\Harvest\HarvestApiService;
use App\Services\TaskAgentService;
use App\Services\TimeEstimationService;
use App\Services\Vault\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createTaskAgentService(?HarvestApiService $harvest = null): TaskAgentService
{
    $executor = Mockery::mock(AgentExecutor::class);
    $vault = Mockery::mock(VaultService::class);
    $vault->shouldReceive('getAgentSecrets')->andReturn([]);
    $timeEstimator = Mockery::mock(TimeEstimationService::class);
    $timeEstimator->shouldReceive('estimate')->andReturn(2.0);

    return new TaskAgentService($executor, $vault, $timeEstimator, $harvest);
}

it('moves task to review and reassigns to user after agent completes', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'output' => ['summary' => 'Implemented the feature successfully'],
        'duration_ms' => 120000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $task->refresh();

    expect($task->status)->toBe('review')
        ->and($task->assigned_to)->toBe($user->id)
        ->and($task->assignee_type)->toBe('user');
});

it('adds system comment with agent summary on completion', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $task = Task::factory()->inProgress()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'output' => ['summary' => 'Built the login page'],
        'duration_ms' => 60000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $systemComment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_SYSTEM)
        ->latest()
        ->first();

    expect($systemComment)->not->toBeNull()
        ->and($systemComment->content)->toContain('Dev Agent completed this task')
        ->and($systemComment->content)->toContain('Built the login page')
        ->and($systemComment->content)->toContain('Review');
});

it('includes PR url in system comment when present', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'output' => [
            'summary' => 'Done',
            'pr_url' => 'https://github.com/org/repo/pull/42',
        ],
        'duration_ms' => 60000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $systemComment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_SYSTEM)
        ->latest()
        ->first();

    expect($systemComment->content)->toContain('https://github.com/org/repo/pull/42');
});

it('does not change status if already in review', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->create([
        'status' => 'review',
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'duration_ms' => 60000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    // Should not have a status_change comment since it was already 'review'
    $statusChange = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_STATUS_CHANGE)
        ->count();

    expect($statusChange)->toBe(0);
});

it('reassigns back to user and adds error comment on failure', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create(['name' => 'QA Agent']);
    $task = Task::factory()->inProgress()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'output' => ['error' => 'Could not access repository'],
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $task->refresh();

    expect($task->assigned_to)->toBe($user->id)
        ->and($task->assignee_type)->toBe('user');

    $errorComment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_SYSTEM)
        ->latest()
        ->first();

    expect($errorComment->content)->toContain('QA Agent failed')
        ->and($errorComment->content)->toContain('Could not access repository');
});

it('marks the agent task as completed on success', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create();

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'duration_ms' => 30000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $agentTask->refresh();

    expect($agentTask->status)->toBe(AgentTask::STATUS_COMPLETED)
        ->and((float) $agentTask->estimated_human_hours)->toBe(2.0);
});

it('marks the agent task as failed on error', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create();

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'output' => ['error' => 'Timeout'],
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    $agentTask->refresh();

    expect($agentTask->status)->toBe(AgentTask::STATUS_FAILED);
});

it('dispatches preview environment job when PR is created with branch', function () {
    Queue::fake();

    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create();

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'output' => [
            'pr_url' => 'https://github.com/org/repo/pull/99',
            'pr_number' => 99,
            'branch' => 'feature/task-assignment',
        ],
        'duration_ms' => 60000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    Queue::assertPushed(\App\Jobs\CreatePreviewEnvironment::class, function ($job) use ($task) {
        return $job->taskId === $task->id;
    });
});

it('does not dispatch preview job when no branch is provided', function () {
    Queue::fake();

    $user = User::factory()->create();
    $agent = Agent::factory()->create();
    $task = Task::factory()->inProgress()->create();

    $agentTask = AgentTask::factory()->forTask($task)->running()->create([
        'agent_id' => $agent->id,
        'assigned_by' => $user->id,
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'output' => [
            'pr_url' => 'https://github.com/org/repo/pull/99',
            'pr_number' => 99,
        ],
        'duration_ms' => 60000,
    ]);

    $service = createTaskAgentService();
    $service->handleAgentCompletion($run, $agentTask);

    Queue::assertNotPushed(\App\Jobs\CreatePreviewEnvironment::class);
});
