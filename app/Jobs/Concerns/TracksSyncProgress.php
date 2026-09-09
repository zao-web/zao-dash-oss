<?php

namespace App\Jobs\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Trait for tracking sync progress on connection models.
 *
 * Connection models should have these columns:
 * - sync_status: string (pending, syncing, completed, failed)
 * - sync_started_at: timestamp
 * - sync_completed_at: timestamp
 * - sync_error: text
 * - sync_progress: integer (0-100)
 */
trait TracksSyncProgress
{
    protected ?Model $syncConnection = null;

    protected function initSyncTracking(Model $connection): void
    {
        $this->syncConnection = $connection;

        $connection->update([
            'sync_status' => 'syncing',
            'sync_started_at' => now(),
            'sync_progress' => 0,
            'sync_error' => null,
        ]);

        Log::info('Sync started', [
            'connection' => get_class($connection),
            'id' => $connection->id,
        ]);
    }

    protected function updateSyncProgress(int $percent, ?string $stage = null): void
    {
        if (! $this->syncConnection) {
            return;
        }

        $this->syncConnection->update([
            'sync_progress' => min(100, max(0, $percent)),
        ]);

        if ($stage) {
            Log::info("Sync progress: {$percent}%", [
                'connection_id' => $this->syncConnection->id,
                'stage' => $stage,
            ]);
        }
    }

    protected function completeSyncTracking(): void
    {
        if (! $this->syncConnection) {
            return;
        }

        $this->syncConnection->update([
            'sync_status' => 'completed',
            'sync_completed_at' => now(),
            'sync_progress' => 100,
            'sync_error' => null,
        ]);

        Log::info('Sync completed', [
            'connection' => get_class($this->syncConnection),
            'id' => $this->syncConnection->id,
        ]);
    }

    protected function failSyncTracking(\Exception $e): void
    {
        if (! $this->syncConnection) {
            return;
        }

        $this->syncConnection->update([
            'sync_status' => 'failed',
            'sync_error' => $e->getMessage(),
        ]);

        Log::error('Sync failed', [
            'connection' => get_class($this->syncConnection),
            'id' => $this->syncConnection->id,
            'error' => $e->getMessage(),
        ]);
    }
}
