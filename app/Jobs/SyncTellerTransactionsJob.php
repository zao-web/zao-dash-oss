<?php

namespace App\Jobs;

use App\Events\AccountSyncProgress;
use App\Models\TransactionCategory;
use App\Services\PersonalFinance\NorthStarService;
use App\Services\PersonalFinance\TellerService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncTellerTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public bool $forceBalance = false,
    ) {
        $this->onQueue('default');
    }

    public function handle(TellerService $tellerService): void
    {
        Log::info('[SyncTeller] Starting sync', ['user_id' => $this->userId, 'force_balance' => $this->forceBalance]);

        $this->broadcast('starting', 'Starting account sync...', 0, 4);

        $stats = $tellerService->syncTransactions(
            $this->userId,
            forceBalance: $this->forceBalance,
            onProgress: fn (string $step, string $message, int $completed, int $total, ?string $accountName = null) => AccountSyncProgress::dispatch(
                $this->userId, $step, $message, $completed, $total, $accountName
            ),
        );

        Log::info('[SyncTeller] Sync complete', array_merge($stats, ['user_id' => $this->userId]));

        $this->broadcast('categorizing', 'Categorizing transactions...', 2, 4);

        // Ensure transaction categories exist before auto-categorizing
        if (TransactionCategory::count() === 0) {
            Log::info('[SyncTeller] Seeding transaction categories');
            (new \Database\Seeders\TransactionCategorySeeder)->run();
        }

        // Auto-categorize all uncategorized transactions after sync
        $categorizationService = app(TransactionCategorizationService::class);
        $categorized = $categorizationService->autoCategorizeAll($this->userId);
        Log::info('[SyncTeller] Auto-categorized transactions', ['categorized' => $categorized]);

        $this->broadcast('north_star', 'Updating goals...', 3, 4);

        // Capture North Star milestone progress after sync
        $northStarService = app(NorthStarService::class);
        $northStarService->captureProgress($this->userId);
        Log::info('[SyncTeller] North Star progress captured', ['user_id' => $this->userId]);

        $this->broadcast('complete', "Sync complete — {$stats['synced']} transactions, {$categorized} categorized.", 4, 4);
    }

    protected function broadcast(string $step, string $message, int $completed, int $total, ?string $accountName = null): void
    {
        AccountSyncProgress::dispatch($this->userId, $step, $message, $completed, $total, $accountName);
    }
}
