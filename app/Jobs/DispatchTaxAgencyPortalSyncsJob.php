<?php

namespace App\Jobs;

use App\Models\TaxAgencyConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTaxAgencyPortalSyncsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        $years = array_values(array_unique([
            now()->year,
            now()->subYear()->year,
        ]));

        TaxAgencyConnection::query()
            ->active()
            ->each(function (TaxAgencyConnection $connection) use ($years): void {
                foreach ($years as $year) {
                    SyncTaxAgencyPortalJob::dispatch($connection->id, $year);
                }
            });
    }
}
