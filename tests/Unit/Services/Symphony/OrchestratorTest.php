<?php

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Task;
use App\Services\Symphony\Orchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches a task-linked agent task using workflow prompt and workspace', function () {
    Queue::fake();

    $agent = Agent::factory()->active()->create();
    $task = Task::factory()->pending()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->create([
        'agent_id' => $agent->id,
        'status' => AgentTask::STATUS_PENDING,
    ]);

    $workspaceRoot = sys_get_temp_dir().'/symphony-workspace-'.uniqid();
    $workflowPath = sys_get_temp_dir().'/workflow-orchestrator-'.uniqid().'.md';
    file_put_contents($workflowPath, <<<MD
---
tracker:
  kind: kanban_tasks
  active_states: [pending, in_progress]
  terminal_states: [completed]
workspace:
  root: {$workspaceRoot}
agent:
  max_concurrent_agents: 5
codex:
  command: codex app-server
---
Issue {{ issue.identifier }} / Attempt {{ attempt }}
MD);

    app(Orchestrator::class)->runTick($workflowPath);

    Queue::assertPushed(ExecuteAgentJob::class, function (ExecuteAgentJob $job) use ($agentTask) {
        return $job->invocationSource === 'task'
            && $job->triggerMetadata['agent_task_id'] === $agentTask->id
            && str_contains($job->config['prompt'], 'TASK-'.$agentTask->task_id)
            && ! empty($job->config['workspace_path']);
    });

    expect($agentTask->fresh()->status)->toBe(AgentTask::STATUS_RUNNING)
        ->and($agentTask->fresh()->workspace_path)->not->toBeNull();

    @unlink($workflowPath);
    File::deleteDirectory($workspaceRoot);
});

it('queues retry when workflow prompt rendering fails', function () {
    Queue::fake();

    $agent = Agent::factory()->active()->create();
    $task = Task::factory()->pending()->create([
        'assigned_to' => $agent->id,
        'assignee_type' => 'agent',
    ]);

    $agentTask = AgentTask::factory()->forTask($task)->create([
        'agent_id' => $agent->id,
        'status' => AgentTask::STATUS_PENDING,
    ]);

    $workspaceRoot = sys_get_temp_dir().'/symphony-workspace-'.uniqid();
    $workflowPath = sys_get_temp_dir().'/workflow-orchestrator-'.uniqid().'.md';
    file_put_contents($workflowPath, <<<MD
---
tracker:
  kind: kanban_tasks
workspace:
  root: {$workspaceRoot}
agent:
  max_concurrent_agents: 5
codex:
  command: codex app-server
---
Issue {{ issue.missing_field }}
MD);

    app(Orchestrator::class)->runTick($workflowPath);

    Queue::assertNotPushed(ExecuteAgentJob::class);

    $agentTask->refresh();
    expect($agentTask->status)->toBe(AgentTask::STATUS_RETRY_QUEUED)
        ->and($agentTask->retry_attempt)->toBe(1)
        ->and($agentTask->retry_due_at)->not->toBeNull();

    @unlink($workflowPath);
    File::deleteDirectory($workspaceRoot);
});
