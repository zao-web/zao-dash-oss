<?php

namespace App\Jobs;

use App\Services\PersonalFinance\BookkeepingCleanupStatusService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBookkeepingCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $taxYear,
        public bool $businessOnly = true,
    ) {
        $this->onQueue('integrations');
    }

    /**
     * Execute the job.
     */
    public function handle(
        TransactionCategorizationService $transactionCategorizationService,
        BookkeepingCleanupStatusService $bookkeepingCleanupStatusService,
    ): void {
        $bookkeepingCleanupStatusService->markStarted($this->userId, $this->taxYear, $this->businessOnly);

        try {
            $result = $transactionCategorizationService->runBookkeepingCleanup(
                userId: $this->userId,
                year: $this->taxYear,
                businessOnly: $this->businessOnly,
            );

            $bookkeepingCleanupStatusService->markCompleted($this->userId, $this->taxYear, $this->businessOnly, $result);
        } catch (\Throwable $exception) {
            $bookkeepingCleanupStatusService->markFailed(
                $this->userId,
                $this->taxYear,
                $this->businessOnly,
                $exception->getMessage(),
            );

            throw $exception;
        }
    }
}
