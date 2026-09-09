<?php

namespace App\Observers;

use App\Jobs\PushTaskStatusToSheetJob;
use App\Models\ExternalTaskSource;
use App\Models\Task;
use App\Services\GoogleSheets\SheetTaskSyncService;

class TaskObserver
{
    /**
     * Stamp completed_at on the transition into 'completed', and clear it if a
     * task is reopened. Runs on saving() so the value persists in the same
     * write, regardless of which path (controller, MCP, sheet sync) changed
     * the status. Only touches completed_at when status actually changes, so a
     * manual completed_at edit isn't clobbered by an unrelated save.
     */
    public function saving(Task $task): void
    {
        if (! $task->isDirty('status')) {
            return;
        }

        if ($task->status === 'completed' && $task->completed_at === null) {
            $task->completed_at = now();
        } elseif ($task->status !== 'completed' && $task->completed_at !== null) {
            $task->completed_at = null;
        }
    }

    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        $sheetStatus = SheetTaskSyncService::TASK_TO_SHEET_STATUS[$task->status] ?? null;
        if ($sheetStatus === null) {
            // We only push Underway / Reviewing back to client sheets. pending
            // and completed are owned by the client.
            return;
        }

        $task->loadMissing('externalMappings.source');
        foreach ($task->externalMappings as $mapping) {
            if ($mapping->source?->type !== ExternalTaskSource::TYPE_GOOGLE_SHEET) {
                continue;
            }
            PushTaskStatusToSheetJob::dispatch($mapping->id, $sheetStatus);
        }
    }
}
