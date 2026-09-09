<?php

namespace App\Services\ClickUp;

use App\Models\ExternalTaskMapping;
use App\Models\ExternalTaskSource;
use App\Models\PmConnection;
use App\Models\PmSyncLog;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ClickUpTaskSyncService
{
    public function __construct(
        protected ClickUpApiService $api
    ) {}

    /**
     * Sync all tasks from an external source
     */
    public function syncSource(ExternalTaskSource $source): PmSyncLog
    {
        $startTime = microtime(true);
        $connection = $source->connection;

        $log = PmSyncLog::start($connection, PmSyncLog::OP_FULL_SYNC, $source);

        try {
            // Get last sync time for incremental updates
            $lastSyncTimestamp = $source->last_synced_at
                ? $source->last_synced_at->timestamp * 1000 // ClickUp uses ms
                : null;

            // Fetch tasks from ClickUp
            $tasks = $this->api->getTasksFromList(
                $connection,
                $source->external_id,
                false,
                true,
                $lastSyncTimestamp
            );

            foreach ($tasks as $clickUpTask) {
                try {
                    $this->processTask($source, $clickUpTask, $log);
                } catch (\Exception $e) {
                    $log->recordError("Task {$clickUpTask['id']}: ".$e->getMessage());
                    Log::error('ClickUp task sync failed', [
                        'task_id' => $clickUpTask['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $source->update(['last_synced_at' => now()]);

        } catch (\Exception $e) {
            $log->recordError('Sync failed: '.$e->getMessage());
            Log::error('ClickUp source sync failed', [
                'source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $log->finish($durationMs);

        return $log;
    }

    /**
     * Process a single ClickUp task
     */
    protected function processTask(ExternalTaskSource $source, array $clickUpTask, PmSyncLog $log): void
    {
        $externalId = $clickUpTask['id'];

        // Check if we already have this task mapped
        $mapping = ExternalTaskMapping::where('external_task_source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if ($mapping) {
            // Update existing task
            $this->updateTask($mapping, $clickUpTask, $source, $log);
        } else {
            // Create new task
            $this->createTask($source, $clickUpTask, $log);
        }
    }

    /**
     * Create a new internal task from ClickUp
     */
    protected function createTask(ExternalTaskSource $source, array $clickUpTask, PmSyncLog $log): Task
    {
        // Map assignee by email
        $assigneeId = $this->resolveAssignee($clickUpTask['assignees'] ?? []);

        // Map status
        $status = $source->mapStatus($clickUpTask['status']['status'] ?? 'open');

        // Parse due date
        $dueDate = null;
        if (! empty($clickUpTask['due_date'])) {
            $dueDate = \Carbon\Carbon::createFromTimestampMs($clickUpTask['due_date']);
        }

        // Parse priority (ClickUp: 1=urgent, 2=high, 3=normal, 4=low)
        $priority = $this->mapPriority($clickUpTask['priority'] ?? null);

        // Create the task
        $task = Task::create([
            'project_id' => $source->project_id,
            'title' => $clickUpTask['name'],
            'description' => $clickUpTask['description'] ?? null,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'assigned_to' => $assigneeId,
        ]);

        // Create the mapping
        ExternalTaskMapping::create([
            'task_id' => $task->id,
            'external_task_source_id' => $source->id,
            'external_id' => $clickUpTask['id'],
            'external_url' => $clickUpTask['url'] ?? null,
            'external_data' => $clickUpTask,
            'sync_status' => ExternalTaskMapping::STATUS_SYNCED,
            'sync_direction' => ExternalTaskMapping::DIRECTION_INBOUND,
            'external_updated_at' => isset($clickUpTask['date_updated'])
                ? \Carbon\Carbon::createFromTimestampMs($clickUpTask['date_updated'])
                : now(),
            'last_synced_at' => now(),
        ]);

        $log->taskCreated();

        // Sync comments
        $this->syncComments($source->connection, $task, $clickUpTask['id']);

        Log::info('Created task from ClickUp', [
            'task_id' => $task->id,
            'clickup_id' => $clickUpTask['id'],
        ]);

        return $task;
    }

    /**
     * Update an existing task from ClickUp changes
     */
    protected function updateTask(
        ExternalTaskMapping $mapping,
        array $clickUpTask,
        ExternalTaskSource $source,
        PmSyncLog $log
    ): void {
        $task = $mapping->task;
        $clickUpUpdatedAt = isset($clickUpTask['date_updated'])
            ? \Carbon\Carbon::createFromTimestampMs($clickUpTask['date_updated'])
            : now();

        // Check for conflict
        if ($mapping->last_synced_at && $task->updated_at->gt($mapping->last_synced_at)) {
            // Internal task was modified after last sync
            if ($clickUpUpdatedAt->gt($mapping->external_updated_at ?? now()->subYear())) {
                // External also changed - conflict!
                $mapping->update([
                    'sync_status' => ExternalTaskMapping::STATUS_CONFLICT,
                    'external_data' => $clickUpTask,
                    'external_updated_at' => $clickUpUpdatedAt,
                ]);
                $log->taskSkipped();
                Log::warning('Task sync conflict detected', [
                    'task_id' => $task->id,
                    'clickup_id' => $clickUpTask['id'],
                ]);

                return;
            }
        }

        // Map fields
        $assigneeId = $this->resolveAssignee($clickUpTask['assignees'] ?? []);
        $status = $source->mapStatus($clickUpTask['status']['status'] ?? 'open');

        $dueDate = null;
        if (! empty($clickUpTask['due_date'])) {
            $dueDate = \Carbon\Carbon::createFromTimestampMs($clickUpTask['due_date']);
        }

        $priority = $this->mapPriority($clickUpTask['priority'] ?? null);

        // Update task
        $task->update([
            'title' => $clickUpTask['name'],
            'description' => $clickUpTask['description'] ?? $task->description,
            'status' => $status,
            'priority' => $priority,
            'due_date' => $dueDate,
            'assigned_to' => $assigneeId ?? $task->assigned_to,
        ]);

        // Update mapping
        $mapping->update([
            'external_data' => $clickUpTask,
            'external_updated_at' => $clickUpUpdatedAt,
            'sync_status' => ExternalTaskMapping::STATUS_SYNCED,
            'last_synced_at' => now(),
        ]);

        // Sync any new comments
        $this->syncComments($source->connection, $task, $clickUpTask['id']);

        $log->taskUpdated();
    }

    /**
     * Resolve assignee by email matching
     */
    protected function resolveAssignee(array $assignees): ?int
    {
        if (empty($assignees)) {
            return null;
        }

        // Get first assignee's email
        $clickUpAssignee = $assignees[0] ?? null;
        if (! $clickUpAssignee || empty($clickUpAssignee['email'])) {
            return null;
        }

        // Find user by email
        $user = User::where('email', $clickUpAssignee['email'])->first();

        return $user?->id;
    }

    /**
     * Map ClickUp priority to internal
     */
    protected function mapPriority(?array $priority): string
    {
        if (! $priority || ! isset($priority['priority'])) {
            return 'medium';
        }

        return match ($priority['priority']) {
            'urgent', 1 => 'urgent',
            'high', 2 => 'high',
            'normal', 3 => 'medium',
            'low', 4 => 'low',
            default => 'medium',
        };
    }

    /**
     * Sync comments from ClickUp for a task
     */
    protected function syncComments(PmConnection $connection, Task $task, string $clickUpTaskId): void
    {
        try {
            $comments = $this->api->getTaskComments($connection, $clickUpTaskId);

            foreach ($comments as $comment) {
                $externalId = $comment['id'];

                // Skip if already synced
                if (TaskComment::externalCommentExists($externalId, 'clickup')) {
                    continue;
                }

                // Extract comment text (prefer plain text, fallback to concatenating parts)
                $content = $comment['comment_text'] ?? '';
                if (empty($content) && ! empty($comment['comment'])) {
                    $content = collect($comment['comment'])
                        ->pluck('text')
                        ->filter()
                        ->implode('');
                }

                if (empty($content)) {
                    continue;
                }

                // Get author info
                $authorName = $comment['user']['username'] ?? $comment['user']['email'] ?? 'Unknown';
                $authorEmail = $comment['user']['email'] ?? null;

                // Parse created timestamp
                $createdAt = isset($comment['date'])
                    ? \Carbon\Carbon::createFromTimestampMs($comment['date'])
                    : now();

                TaskComment::createExternalComment(
                    $task,
                    $content,
                    $externalId,
                    'clickup',
                    $authorName,
                    $authorEmail,
                    $createdAt
                );
            }
        } catch (\Exception $e) {
            Log::warning('Failed to sync ClickUp comments', [
                'task_id' => $task->id,
                'clickup_task_id' => $clickUpTaskId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync changes back to ClickUp (for bidirectional sync)
     */
    public function syncBack(ExternalTaskMapping $mapping): bool
    {
        if (! $mapping->source->sync_back) {
            return false;
        }

        $task = $mapping->task;
        $connection = $mapping->source->connection;

        try {
            // Map status back to ClickUp
            $clickUpStatus = $mapping->source->mapStatusToExternal($task->status);

            $data = [
                'name' => $task->title,
                'description' => $task->description ?? '',
            ];

            if ($clickUpStatus) {
                $data['status'] = $clickUpStatus;
            }

            if ($task->due_date) {
                $data['due_date'] = $task->due_date->timestamp * 1000;
            }

            $this->api->updateTask($connection, $mapping->external_id, $data);

            $mapping->markSynced();

            Log::info('Synced task back to ClickUp', [
                'task_id' => $task->id,
                'clickup_id' => $mapping->external_id,
            ]);

            return true;

        } catch (\Exception $e) {
            $mapping->markError();
            Log::error('Failed to sync back to ClickUp', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
