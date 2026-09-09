<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\ExternalTaskSource;
use App\Models\PmConnection;
use App\Services\ClickUp\ClickUpTaskSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncClickUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $connectionId = null,
        public ?int $sourceId = null
    ) {}

    public function handle(ClickUpTaskSyncService $syncService): void
    {
        // Sync specific source
        if ($this->sourceId) {
            $source = ExternalTaskSource::find($this->sourceId);
            if ($source && $source->auto_import) {
                $this->syncSource($source, $syncService);
            }

            return;
        }

        // Sync specific connection
        if ($this->connectionId) {
            $connection = PmConnection::find($this->connectionId);
            if ($connection && $connection->is_active) {
                $this->syncConnection($connection, $syncService);
            }

            return;
        }

        // Sync all active ClickUp connections
        $connections = PmConnection::active()
            ->clickUp()
            ->with('taskSources')
            ->get();

        foreach ($connections as $connection) {
            try {
                $this->syncConnection($connection, $syncService);
            } catch (\Exception $e) {
                Log::error('ClickUp connection sync failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncConnection(PmConnection $connection, ClickUpTaskSyncService $syncService): void
    {
        $this->initSyncTracking($connection);

        try {
            Log::info('Syncing ClickUp connection', [
                'connection_id' => $connection->id,
                'workspace' => $connection->workspace_name,
            ]);

            $sources = $connection->taskSources()->autoImport()->get();
            $totalSources = $sources->count();

            foreach ($sources as $index => $source) {
                try {
                    $this->syncSource($source, $syncService);
                    $progress = (int) (($index + 1) / max(1, $totalSources) * 95);
                    $this->updateSyncProgress($progress, "source: {$source->name}");
                } catch (\Exception $e) {
                    Log::error('ClickUp source sync failed', [
                        'source_id' => $source->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $connection->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncSource(ExternalTaskSource $source, ClickUpTaskSyncService $syncService): void
    {
        Log::info('Syncing ClickUp source', [
            'source_id' => $source->id,
            'name' => $source->name,
        ]);

        $log = $syncService->syncSource($source);

        Log::info('ClickUp source sync complete', [
            'source_id' => $source->id,
            'status' => $log->status,
            'created' => $log->tasks_created,
            'updated' => $log->tasks_updated,
            'skipped' => $log->tasks_skipped,
            'errors' => $log->errors_count,
            'duration_ms' => $log->duration_ms,
        ]);
    }
}
