<?php

namespace App\Services;

use App\Jobs\CreatePreviewEnvironment;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\Agents\AgentExecutor;
use App\Services\Harvest\HarvestApiService;
use App\Services\Symphony\WorkflowConfig;
use App\Services\Symphony\WorkflowLoader;
use App\Services\Symphony\WorkspaceManager;
use App\Services\Vault\VaultService;
use Illuminate\Support\Facades\Log;

class TaskAgentService
{
    public function __construct(
        protected AgentExecutor $executor,
        protected VaultService $vault,
        protected TimeEstimationService $timeEstimator,
        protected ?HarvestApiService $harvest = null,
        protected ?WorkflowLoader $workflowLoader = null,
        protected ?WorkspaceManager $workspaceManager = null,
    ) {}

    public function assignAgentToTask(Task $task, Agent $agent, ?User $user = null): AgentTask
    {
        $projectId = $task->project_id;
        $clientId = $task->project?->client_id;

        $agentTask = AgentTask::create([
            'agent_id' => $agent->id,
            'task_id' => $task->id,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'task_description' => $this->buildTaskDescription($task),
            'context' => $this->buildExecutionContext($task),
            'priority' => $this->mapPriority($task->priority),
            'status' => AgentTask::STATUS_PENDING,
            'assigned_by' => $user?->id,
            'retry_attempt' => null,
            'retry_due_at' => null,
            'last_error' => null,
        ]);

        TaskActivity::logAgentAssigned($task, $agent, $user);

        Log::info('Agent assigned to task', [
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'agent_task_id' => $agentTask->id,
        ]);

        return $agentTask;
    }

    public function executeAgentTask(AgentTask $agentTask): AgentRun
    {
        $task = $agentTask->task;
        $agent = $agentTask->agent;

        $agentTask->start();

        $credentials = $this->loadCredentials($agentTask);

        if ($task && ! empty($credentials)) {
            TaskActivity::logCredentialsLoaded($task, array_keys($credentials));
        }

        $config = [
            'prompt' => $agentTask->task_description,
            'context' => array_merge(
                $agentTask->getExecutionContext(),
                ['credentials' => $credentials]
            ),
        ];

        $run = $this->executor->execute(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_TASK,
            invokedBy: $agentTask->assigned_by ? "user:{$agentTask->assigned_by}" : null,
            triggerMetadata: [
                'agent_task_id' => $agentTask->id,
                'task_id' => $agentTask->task_id,
                'project_id' => $agentTask->project_id,
            ]
        );

        if ($task) {
            TaskActivity::logAgentStarted($task, $run);
        }

        return $run;
    }

    public function handleAgentCompletion(AgentRun $run, AgentTask $agentTask): void
    {
        $task = $agentTask->task;
        $agentSeconds = $run->duration_ms ? $run->duration_ms / 1000 : null;

        try {
            if ($run->status === 'completed') {
                $humanHours = $this->timeEstimator->estimate($run, $task);

                $agentTask->update([
                    'estimated_human_hours' => $humanHours,
                    'actual_agent_seconds' => $agentSeconds,
                ]);

                $agentTask->complete($run->output ?? [], $run->id);

                if ($task) {
                    TaskActivity::logAgentCompleted($task, $run, $humanHours, $agentSeconds);
                    $this->logHarvestTimeEntry($task, $run, $agentTask, $humanHours);
                    $this->checkForPrCreation($task, $run);
                    $this->handoffAfterCompletion($task, $agentTask, $run);
                }

                Log::info('Agent task completed', [
                    'agent_task_id' => $agentTask->id,
                    'human_hours' => $humanHours,
                    'agent_seconds' => $agentSeconds,
                ]);
            } else {
                $error = $run->output['error'] ?? 'Unknown error';

                if ($this->shouldUseSymphonyRetry($agentTask)) {
                    $this->queueSymphonyRetry($agentTask, $error, $run->id);

                    if ($task) {
                        TaskActivity::logAgentFailed($task, $run, $error);
                    }

                    Log::warning('Agent task failed and was queued for Symphony retry', [
                        'agent_task_id' => $agentTask->id,
                        'task_id' => $agentTask->task_id,
                        'error' => $error,
                    ]);

                    return;
                }

                $agentTask->fail($error, $run->id);

                if ($task) {
                    TaskActivity::logAgentFailed($task, $run, $error);
                    $this->handoffAfterFailure($task, $agentTask);
                }

                Log::warning('Agent task failed', [
                    'agent_task_id' => $agentTask->id,
                    'error' => $error,
                ]);
            }
        } finally {
            $this->runAfterRunHookIfConfigured($agentTask);
        }
    }

    /**
     * After an agent completes a task, move to review and reassign
     * to the person who assigned the agent.
     */
    protected function handoffAfterCompletion(Task $task, AgentTask $agentTask, AgentRun $run): void
    {
        $agent = $agentTask->agent;
        $assignedById = $agentTask->assigned_by;

        // Move task to review status
        $oldStatus = $task->status;
        if ($oldStatus !== 'review' && $oldStatus !== 'completed') {
            $task->update(['status' => 'review']);
            TaskComment::logStatusChange($task, null, $oldStatus, 'review');
        }

        // Reassign to the person who originally assigned the agent
        if ($assignedById) {
            $oldAssigneeId = $task->assigned_to;
            $oldAssigneeType = $task->assignee_type;

            $task->update([
                'assigned_to' => $assignedById,
                'assignee_type' => 'user',
            ]);

            TaskComment::logAssignment(
                $task,
                null,
                $oldAssigneeId,
                $assignedById,
                $oldAssigneeType ?? 'agent',
                'user'
            );
        }

        // Add a system comment summarizing what the agent did
        $output = $run->output ?? [];
        $summary = $output['summary'] ?? $output['message'] ?? null;
        $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;

        $commentParts = ["{$agent->name} completed this task."];

        if ($summary) {
            $commentParts[] = "\n\n**Summary:** {$summary}";
        }

        if ($prUrl) {
            $commentParts[] = "\n\n**Pull Request:** {$prUrl}";
        }

        $commentParts[] = "\n\nTask moved to **Review** for your sign-off.";

        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => null,
            'type' => TaskComment::TYPE_SYSTEM,
            'content' => implode('', $commentParts),
            'metadata' => [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'agent_run_id' => $run->id,
                'pr_url' => $prUrl,
            ],
        ]);

        Log::info('Agent handoff completed', [
            'task_id' => $task->id,
            'reassigned_to' => $assignedById,
            'new_status' => 'review',
        ]);
    }

    /**
     * After an agent fails, reassign back to the assigner with context.
     */
    protected function handoffAfterFailure(Task $task, AgentTask $agentTask): void
    {
        $agent = $agentTask->agent;
        $assignedById = $agentTask->assigned_by;

        // Reassign back to the person who assigned the agent
        if ($assignedById) {
            $oldAssigneeId = $task->assigned_to;
            $oldAssigneeType = $task->assignee_type;

            $task->update([
                'assigned_to' => $assignedById,
                'assignee_type' => 'user',
            ]);

            TaskComment::logAssignment(
                $task,
                null,
                $oldAssigneeId,
                $assignedById,
                $oldAssigneeType ?? 'agent',
                'user'
            );
        }

        $error = $agentTask->result['error'] ?? 'Unknown error';
        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => null,
            'type' => TaskComment::TYPE_SYSTEM,
            'content' => "{$agent->name} failed on this task.\n\n**Error:** {$error}\n\nTask reassigned back to you for manual action.",
            'metadata' => [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'error' => $error,
            ],
        ]);
    }

    protected function loadCredentials(AgentTask $agentTask): array
    {
        $agent = $agentTask->agent;

        return $this->vault->getAgentSecrets(
            $agent->slug,
            $agentTask->project_id,
            $agentTask->client_id
        );
    }

    protected function logHarvestTimeEntry(
        Task $task,
        AgentRun $run,
        AgentTask $agentTask,
        float $humanHours
    ): void {
        if (! $this->harvest) {
            return;
        }

        $project = $task->project;
        if (! $project || ! $project->harvest_project_id) {
            Log::info('No Harvest project linked, skipping time entry', [
                'task_id' => $task->id,
            ]);

            return;
        }

        try {
            $user = $agentTask->assignedBy;
            if (! $user) {
                $user = User::where('role', 'owner')->first();
            }

            if (! $user?->harvestCredential) {
                Log::warning('No Harvest credentials available for time entry', [
                    'task_id' => $task->id,
                ]);

                return;
            }

            $harvestEntry = $this->harvest->createTimeEntry($user, [
                'project_id' => $project->harvest_project_id,
                'task_id' => $this->getHarvestTaskId($project),
                'spent_date' => now()->format('Y-m-d'),
                'hours' => $humanHours,
                'notes' => $this->buildTimeEntryNotes($task, $run, $agentTask),
            ]);

            $harvestEntryId = $harvestEntry['id'] ?? null;

            $agentTask->update(['harvest_time_entry_id' => $harvestEntryId]);

            TaskActivity::logTimeEntry($task, $humanHours, $harvestEntryId, null, $run);

            Log::info('Harvest time entry created', [
                'task_id' => $task->id,
                'harvest_entry_id' => $harvestEntryId,
                'hours' => $humanHours,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Harvest time entry', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getHarvestTaskId($project): ?int
    {
        // TODO: Add harvest_task_id to projects or use a default development task
        return null;
    }

    protected function buildTimeEntryNotes(Task $task, AgentRun $run, AgentTask $agentTask): string
    {
        $parts = [
            "Task: {$task->title}",
            "Completed by AI Agent ({$run->agent->name})",
            'Agent execution: '.round($agentTask->actual_agent_seconds ?? 0).'s',
            "Human-equivalent estimate: {$agentTask->estimated_human_hours}h",
        ];

        return implode("\n", $parts);
    }

    protected function checkForPrCreation(Task $task, AgentRun $run): void
    {
        $output = $run->output ?? [];

        $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;
        $prNumber = $output['pr_number'] ?? null;
        $branch = $output['branch'] ?? $output['branch_name'] ?? null;

        if ($prUrl && $prNumber) {
            TaskActivity::logPrCreated($task, $prUrl, (string) $prNumber, $run);

            // Create a preview environment if we have a branch name
            if ($branch) {
                CreatePreviewEnvironment::dispatch(
                    $task->id,
                    $branch,
                    $prUrl,
                    (string) $prNumber
                );
            }
        }
    }

    protected function buildTaskDescription(Task $task): string
    {
        $parts = ["Task: {$task->title}"];

        if ($task->description) {
            $parts[] = "\nDescription:\n{$task->description}";
        }

        if ($task->project) {
            $parts[] = "\nProject: {$task->project->name}";

            if ($task->project->github_repo) {
                $parts[] = "Repository: {$task->project->github_repo}";
            }
        }

        return implode("\n", $parts);
    }

    protected function buildExecutionContext(Task $task): array
    {
        $context = [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'task_priority' => $task->priority,
        ];

        if ($task->project) {
            $context['project'] = [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'github_repo' => $task->project->github_repo,
            ];

            if ($task->project->client) {
                $context['client'] = [
                    'id' => $task->project->client->id,
                    'name' => $task->project->client->name,
                ];
            }
        }

        return $context;
    }

    protected function mapPriority(?string $taskPriority): string
    {
        return match ($taskPriority) {
            'urgent' => AgentTask::PRIORITY_URGENT,
            'high' => AgentTask::PRIORITY_HIGH,
            'low' => AgentTask::PRIORITY_LOW,
            default => AgentTask::PRIORITY_NORMAL,
        };
    }

    protected function shouldUseSymphonyRetry(AgentTask $agentTask): bool
    {
        if (! $agentTask->workspace_path || ! $agentTask->task) {
            return false;
        }

        return $agentTask->task->assignee_type === 'agent'
            && (int) $agentTask->task->assigned_to === (int) $agentTask->agent_id;
    }

    protected function queueSymphonyRetry(AgentTask $agentTask, string $error, ?int $agentRunId = null): void
    {
        $maxRetryBackoffMs = 300000;

        try {
            $workflowLoader = $this->workflowLoader ??= app(WorkflowLoader::class);
            $workflow = $workflowLoader->load();
            $config = new WorkflowConfig($workflow['config']);
            $maxRetryBackoffMs = $config->maxRetryBackoffMs();
        } catch (\Throwable $exception) {
            Log::warning('Falling back to default Symphony retry backoff config', [
                'agent_task_id' => $agentTask->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $attempt = max(((int) ($agentTask->retry_attempt ?? 0)) + 1, 1);
        $delayMs = min(10000 * (2 ** ($attempt - 1)), $maxRetryBackoffMs);

        $agentTask->queueRetry(
            attempt: $attempt,
            delayMilliseconds: $delayMs,
            error: $error,
            agentRunId: $agentRunId
        );

        if ($agentTask->task) {
            TaskComment::create([
                'task_id' => $agentTask->task->id,
                'user_id' => null,
                'type' => TaskComment::TYPE_SYSTEM,
                'content' => "{$agentTask->agent->name} hit an execution error and will retry automatically.\n\n**Error:** {$error}\n\n**Retry attempt:** {$attempt}",
                'metadata' => [
                    'agent_id' => $agentTask->agent_id,
                    'agent_name' => $agentTask->agent->name,
                    'retry_attempt' => $attempt,
                    'retry_delay_ms' => $delayMs,
                ],
            ]);
        }
    }

    protected function runAfterRunHookIfConfigured(AgentTask $agentTask): void
    {
        if (! $agentTask->workspace_path) {
            return;
        }

        try {
            $workflowLoader = $this->workflowLoader ??= app(WorkflowLoader::class);
            $workflow = $workflowLoader->load();
            $config = new WorkflowConfig($workflow['config']);

            $workspaceManager = $this->workspaceManager ??= app(WorkspaceManager::class);
            $workspaceManager->runAfterRun($agentTask->workspace_path, $config);
        } catch (\Throwable $exception) {
            Log::warning('Failed to execute Symphony after_run hook', [
                'agent_task_id' => $agentTask->id,
                'workspace_path' => $agentTask->workspace_path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function getEstimationBreakdown(AgentRun $run, ?Task $task = null): array
    {
        return $this->timeEstimator->getEstimationBreakdown($run, $task);
    }
}
