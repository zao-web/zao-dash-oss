<?php

namespace App\Jobs;

use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ComputeRetainerSnapshot implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public RetainerPeriod $retainer
    ) {}

    public function handle(RetainerHealthService $service): void
    {
        $service->computeAndPersistSnapshot(
            $this->retainer,
            $this->retainer->period_start,
            $this->retainer->period_end,
            persist: true,
        );
    }
}
