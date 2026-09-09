<?php

namespace App\Jobs;

use App\Models\RetainerPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ComputeAllRetainerSnapshots implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        RetainerPeriod::active()
            ->each(function (RetainerPeriod $retainer) {
                ComputeRetainerSnapshot::dispatch($retainer);
            });
    }
}
