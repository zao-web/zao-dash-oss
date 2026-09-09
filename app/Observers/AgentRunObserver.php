<?php

namespace App\Observers;

use App\Events\NotificationCreated;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\TaskAgentService;
use Illuminate\Support\Facades\Log;

class AgentRunObserver
{
    public function __construct(
        protected ?TaskAgentService $taskAgentService = null
    ) {}

    public function updated(AgentRun $run): void
    {
        if (! $run->wasChanged('status')) {
            return;
        }

        if (! in_array($run->status, ['completed', 'failed'])) {
            return;
        }

        $notification = Notification::agentCompleted($run);
        broadcast(new NotificationCreated($notification))->toOthers();

        $this->handleTaskAgentCompletion($run);
        $this->logTaskActivityForDirectRuns($run);
    }

    protected function logTaskActivityForDirectRuns(AgentRun $run): void
    {
        if ($run->invocation_source === AgentRun::SOURCE_TASK) {
            return;
        }

        if (! $run->task_id) {
            return;
        }

        $task = Task::find($run->task_id);
        if (! $task) {
            Log::warning('Task not found for agent run', [
                'run_id' => $run->id,
                'task_id' => $run->task_id,
            ]);

            return;
        }

        try {
            if ($run->status === 'completed') {
                TaskActivity::logAgentCompleted($task, $run);
                $this->checkForPrCreation($task, $run);
            } else {
                $error = $run->output['error'] ?? $run->error_message ?? 'Unknown error';
                TaskActivity::logAgentFailed($task, $run, $error);
            }

            Log::info('Task activity logged for direct agent run', [
                'run_id' => $run->id,
                'task_id' => $run->task_id,
                'status' => $run->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log task activity for agent run', [
                'run_id' => $run->id,
                'task_id' => $run->task_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkForPrCreation(Task $task, AgentRun $run): void
    {
        $output = $run->output ?? [];

        $prUrl = $output['pr_url'] ?? $output['pull_request_url'] ?? null;
        $prNumber = $output['pr_number'] ?? null;

        if (! $prUrl) {
            $result = $output['result'] ?? '';
            if (is_string($result)) {
                if (preg_match('#https://github\.com/[^/]+/[^/]+/pull/(\d+)#', $result, $matches)) {
                    $prUrl = $matches[0];
                    $prNumber = $matches[1];
                }
            }
        }

        if ($prUrl && $prNumber) {
            TaskActivity::logPrCreated($task, $prUrl, (string) $prNumber, $run);

            // Automatically move task to review when PR is created
            if (in_array($task->status, ['pending', 'in_progress'])) {
                $task->update(['status' => 'review']);
                Log::info('Task status updated to review (PR created)', [
                    'task_id' => $task->id,
                    'pr_url' => $prUrl,
                    'agent_run_id' => $run->id,
                ]);
            }
        }
    }

    protected function handleTaskAgentCompletion(AgentRun $run): void
    {
        if ($run->invocation_source !== AgentRun::SOURCE_TASK) {
            return;
        }

        $agentTaskId = $run->trigger_metadata['agent_task_id'] ?? null;
        if (! $agentTaskId) {
            Log::warning('Task-initiated run missing agent_task_id in metadata', [
                'run_id' => $run->id,
            ]);

            return;
        }

        $agentTask = AgentTask::find($agentTaskId);
        if (! $agentTask) {
            Log::warning('AgentTask not found for completed run', [
                'run_id' => $run->id,
                'agent_task_id' => $agentTaskId,
            ]);

            return;
        }

        if (! $this->taskAgentService) {
            $this->taskAgentService = app(TaskAgentService::class);
        }

        try {
            $this->taskAgentService->handleAgentCompletion($run, $agentTask);
        } catch (\Exception $e) {
            Log::error('Failed to handle task agent completion', [
                'run_id' => $run->id,
                'agent_task_id' => $agentTaskId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
