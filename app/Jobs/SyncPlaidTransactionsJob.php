<?php

namespace App\Jobs;

use App\Models\PlaidConnection;
use App\Services\PersonalFinance\PlaidService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPlaidTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 60;

    public function __construct(public int $plaidConnectionId)
    {
        $this->onQueue('default');
    }

    public function handle(PlaidService $plaidService): void
    {
        $connection = PlaidConnection::find($this->plaidConnectionId);
        if (! $connection || $connection->status === 'revoked') {
            return;
        }

        Log::info('[SyncPlaid] Starting sync', ['connection_id' => $connection->id, 'institution' => $connection->institution_name]);

        $stats = $plaidService->syncTransactions($connection);

        Log::info('[SyncPlaid] Sync complete', array_merge($stats, ['connection_id' => $connection->id]));

        // Auto-categorize all uncategorized transactions after sync
        $categorizationService = app(TransactionCategorizationService::class);
        $categorized = $categorizationService->autoCategorizeAll($connection->user_id);
        Log::info('[SyncPlaid] Auto-categorized transactions', ['categorized' => $categorized, 'user_id' => $connection->user_id]);
    }
}
