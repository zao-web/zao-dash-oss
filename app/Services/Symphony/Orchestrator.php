<?php

namespace App\Services\Symphony;

use App\Jobs\ExecuteAgentJob;
use App\Models\AgentRun;
use App\Models\AgentTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class Orchestrator
{
    public function __construct(
        protected WorkflowLoader $workflowLoader,
        protected PromptRenderer $promptRenderer,
        protected TaskTrackerClient $trackerClient,
        protected WorkspaceManager $workspaceManager,
    ) {}

    public function runTick(?string $workflowPath = null): void
    {
        try {
            $workflow = $this->workflowLoader->load($workflowPath);
        } catch (WorkflowException $exception) {
            Log::error('Symphony workflow load failed', [
                'reason' => $exception->reason,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $config = new WorkflowConfig($workflow['config']);

        $validationErrors = $config->validateDispatchConfig();
        if (! empty($validationErrors)) {
            Log::warning('Symphony dispatch validation failed', [
                'errors' => $validationErrors,
            ]);

            $this->reconcileRunningIssues($config);

            return;
        }

        $this->reconcileRunningIssues($config);
        $this->cleanupTerminalWorkspaces($config);

        $issues = $this->trackerClient->fetchCandidateIssues($config->activeStates());
        if ($issues->isEmpty()) {
            return;
        }

        $issuesById = $issues->keyBy('id');

        $candidates = $this->candidateAgentTasks($issuesById);
        if ($candidates->isEmpty()) {
            return;
        }

        $runningByState = $this->runningByStateCounts($config);
        $availableGlobalSlots = max($config->maxConcurrentAgents() - $runningByState->sum(), 0);
        if ($availableGlobalSlots <= 0) {
            return;
        }

        $stateLimits = $config->maxConcurrentAgentsByState();
        $template = $workflow['prompt_template'] ?: 'You are working on issue {{ issue.identifier }}: {{ issue.title }}';

        foreach ($candidates as $agentTask) {
            if ($availableGlobalSlots <= 0) {
                break;
            }

            $issue = $issuesById->get((string) $agentTask->task_id);
            if (! $issue) {
                continue;
            }

            $normalizedState = strtolower((string) $issue['state']);
            $stateLimit = $stateLimits[$normalizedState] ?? $config->maxConcurrentAgents();
            $runningForState = (int) ($runningByState[$normalizedState] ?? 0);

            if ($runningForState >= $stateLimit) {
                continue;
            }

            if ($this->dispatchOne($agentTask, $issue, $template, $config)) {
                $runningByState[$normalizedState] = $runningForState + 1;
                $availableGlobalSlots--;
            }
        }
    }

    protected function reconcileRunningIssues(WorkflowConfig $config): void
    {
        $running = AgentTask::query()
            ->with(['task', 'agent'])
            ->whereNotNull('task_id')
            ->where('status', AgentTask::STATUS_RUNNING)
            ->get();

        if ($running->isEmpty()) {
            return;
        }

        $activeStates = $config->activeStates();
        $terminalStates = $config->terminalStates();

        $taskIds = $running->pluck('task_id')->filter()->all();
        $issuesById = $this->trackerClient->fetchIssueStatesByIds($taskIds)->keyBy('id');

        foreach ($running as $agentTask) {
            $issue = $issuesById->get((string) $agentTask->task_id);
            if (! $issue) {
                $this->cancelRunAndRelease($agentTask, 'task missing from tracker state refresh', false);

                continue;
            }

            $state = strtolower((string) $issue['state']);

            if (in_array($state, $terminalStates, true)) {
                $this->cancelRunAndRelease($agentTask, 'task moved to terminal state', true);

                continue;
            }

            if (! in_array($state, $activeStates, true)) {
                $this->cancelRunAndRelease($agentTask, 'task moved to non-active state', false);
            }
        }
    }

    protected function cleanupTerminalWorkspaces(WorkflowConfig $config): void
    {
        $terminalIssues = $this->trackerClient->fetchTerminalIssues($config->terminalStates());

        foreach ($terminalIssues as $issue) {
            try {
                $this->workspaceManager->cleanupForIssue((string) $issue['identifier'], $config);
            } catch (\Throwable $exception) {
                Log::warning('Symphony terminal workspace cleanup failed', [
                    'issue_identifier' => $issue['identifier'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $issuesByTaskId
     * @return Collection<int, AgentTask>
     */
    protected function candidateAgentTasks(Collection $issuesByTaskId): Collection
    {
        $runningTaskIds = AgentTask::query()
            ->where('status', AgentTask::STATUS_RUNNING)
            ->whereNotNull('task_id')
            ->pluck('task_id')
            ->all();

        $candidates = AgentTask::query()
            ->with(['task', 'agent'])
            ->whereNotNull('task_id')
            ->whereIn('status', [AgentTask::STATUS_PENDING, AgentTask::STATUS_RETRY_QUEUED])
            ->where(function ($query) {
                $query->whereNull('retry_due_at')
                    ->orWhere('retry_due_at', '<=', now());
            })
            ->get()
            ->filter(function (AgentTask $agentTask) use ($issuesByTaskId, $runningTaskIds): bool {
                if (! $agentTask->task || ! $agentTask->agent) {
                    return false;
                }

                if (in_array($agentTask->task_id, $runningTaskIds, true)) {
                    return false;
                }

                if ($agentTask->task->assignee_type !== 'agent' || (int) $agentTask->task->assigned_to !== (int) $agentTask->agent_id) {
                    return false;
                }

                return $issuesByTaskId->has((string) $agentTask->task_id);
            })
            ->sort(function (AgentTask $a, AgentTask $b) use ($issuesByTaskId): int {
                $issueA = $issuesByTaskId->get((string) $a->task_id);
                $issueB = $issuesByTaskId->get((string) $b->task_id);

                $priorityA = $issueA['priority'] ?? PHP_INT_MAX;
                $priorityB = $issueB['priority'] ?? PHP_INT_MAX;

                if ($priorityA !== $priorityB) {
                    return $priorityA <=> $priorityB;
                }

                $createdAtA = $issueA['created_at'] ? strtotime((string) $issueA['created_at']) : PHP_INT_MAX;
                $createdAtB = $issueB['created_at'] ? strtotime((string) $issueB['created_at']) : PHP_INT_MAX;

                if ($createdAtA !== $createdAtB) {
                    return $createdAtA <=> $createdAtB;
                }

                return strcmp((string) ($issueA['identifier'] ?? ''), (string) ($issueB['identifier'] ?? ''));
            })
            ->values();

        return $candidates;
    }

    protected function runningByStateCounts(WorkflowConfig $config): Collection
    {
        $activeStates = $config->activeStates();
        $counts = collect();

        $running = AgentTask::query()
            ->with('task:id,status')
            ->where('status', AgentTask::STATUS_RUNNING)
            ->whereNotNull('task_id')
            ->get();

        foreach ($running as $agentTask) {
            $state = strtolower((string) ($agentTask->task?->status ?? ''));
            if (! in_array($state, $activeStates, true)) {
                continue;
            }

            $counts[$state] = ((int) ($counts[$state] ?? 0)) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    protected function dispatchOne(AgentTask $agentTask, array $issue, string $template, WorkflowConfig $config): bool
    {
        if (! $agentTask->agent || $agentTask->agent->status !== 'active') {
            $agentTask->fail('Agent is not active.');

            return false;
        }

        if ($agentTask->agent->circuit_broken_at) {
            $this->scheduleRetry($agentTask, $config, 'Agent circuit breaker is active.');

            return false;
        }

        try {
            $workspace = $this->workspaceManager->createForIssue((string) $issue['identifier'], $config);
            $this->workspaceManager->runBeforeRun($workspace['path'], $config);
            $prompt = $this->promptRenderer->render($template, [
                'issue' => $issue,
                'attempt' => $agentTask->retry_attempt ?: null,
            ]);
        } catch (\Throwable $exception) {
            $this->scheduleRetry($agentTask, $config, $exception->getMessage());

            return false;
        }

        $agentTask->update([
            'status' => AgentTask::STATUS_RUNNING,
            'started_at' => now(),
            'workspace_path' => $workspace['path'],
            'retry_due_at' => null,
            'last_error' => null,
        ]);

        ExecuteAgentJob::dispatch(
            agent: $agentTask->agent,
            config: [
                'prompt' => $prompt,
                'context' => array_merge($agentTask->getExecutionContext(), [
                    'symphony' => [
                        'issue' => $issue,
                        'attempt' => $agentTask->retry_attempt ?: null,
                    ],
                ]),
                'workspace_path' => $workspace['path'],
                'preserve_workspace' => true,
                'timeout_seconds' => 3600,
                'project_id' => $agentTask->project_id,
                'task_id' => $agentTask->task_id,
            ],
            invocationSource: AgentRun::SOURCE_TASK,
            invokedBy: $agentTask->assigned_by ? "user:{$agentTask->assigned_by}" : 'symphony',
            triggerMetadata: [
                'agent_task_id' => $agentTask->id,
                'task_id' => $agentTask->task_id,
                'project_id' => $agentTask->project_id,
                'retry_attempt' => $agentTask->retry_attempt,
                'workspace_path' => $workspace['path'],
            ],
        );

        Log::info('Symphony dispatched agent task', [
            'agent_task_id' => $agentTask->id,
            'task_id' => $agentTask->task_id,
            'issue_identifier' => $issue['identifier'],
        ]);

        return true;
    }

    protected function scheduleRetry(AgentTask $agentTask, WorkflowConfig $config, string $error): void
    {
        $attempt = max(((int) ($agentTask->retry_attempt ?? 0)) + 1, 1);
        $delayMs = min(10000 * (2 ** ($attempt - 1)), $config->maxRetryBackoffMs());

        $agentTask->queueRetry(
            attempt: $attempt,
            delayMilliseconds: $delayMs,
            error: $error
        );

        Log::warning('Symphony queued retry for agent task', [
            'agent_task_id' => $agentTask->id,
            'task_id' => $agentTask->task_id,
            'attempt' => $attempt,
            'delay_ms' => $delayMs,
            'error' => $error,
        ]);
    }

    protected function cancelRunAndRelease(AgentTask $agentTask, string $reason, bool $cleanupWorkspace): void
    {
        $agentRun = AgentRun::query()
            ->where('status', AgentRun::STATUS_RUNNING)
            ->where('invocation_source', AgentRun::SOURCE_TASK)
            ->where('trigger_metadata->agent_task_id', $agentTask->id)
            ->latest('id')
            ->first();

        if ($agentRun) {
            $agentRun->update([
                'status' => AgentRun::STATUS_FAILED,
                'output' => ['error' => "Cancelled by reconciliation: {$reason}"],
                'completed_at' => now(),
            ]);
        }

        $agentTask->update([
            'status' => AgentTask::STATUS_FAILED,
            'completed_at' => now(),
            'last_error' => $reason,
        ]);

        if ($cleanupWorkspace && $agentTask->task_id) {
            $taskIdentifier = 'TASK-'.$agentTask->task_id;
            try {
                $workflow = $this->workflowLoader->load();
                $workflowConfig = new WorkflowConfig($workflow['config']);
                $this->workspaceManager->cleanupForIssue($taskIdentifier, $workflowConfig);
            } catch (\Throwable $exception) {
                Log::warning('Symphony reconciliation cleanup failed', [
                    'task_identifier' => $taskIdentifier,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
