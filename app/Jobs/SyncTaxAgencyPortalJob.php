<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\TaxAgencyConnection;
use App\Services\Tax\Agency\TaxAgencySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncTaxAgencyPortalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public int $tries = 3;

    public int $timeout = 180;

    public int $backoff = 60;

    public function __construct(
        public int $connectionId,
        public int $year,
    ) {
        $this->onQueue('sync');
    }

    public function handle(TaxAgencySyncService $syncService): void
    {
        $connection = TaxAgencyConnection::find($this->connectionId);
        if (! $connection || ! $connection->sync_enabled || $connection->status !== TaxAgencyConnection::STATUS_ACTIVE) {
            return;
        }

        $this->initSyncTracking($connection);

        try {
            $syncService->syncConnection($connection, $this->year);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }
}
