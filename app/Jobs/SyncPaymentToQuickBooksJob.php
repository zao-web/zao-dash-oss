<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\QuickBooks\QuickBooksInvoiceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPaymentToQuickBooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Payment $payment
    ) {
        $this->queue = 'integrations';
    }

    public function handle(QuickBooksInvoiceSyncService $syncService): void
    {
        $syncService->syncPayment($this->payment);
    }

    public function tags(): array
    {
        return [
            'quickbooks-sync',
            "payment:{$this->payment->id}",
        ];
    }
}
