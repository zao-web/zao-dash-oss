<?php

namespace App\Console\Commands;

use App\Services\QuickBooks\QuickBooksInvoiceSyncService;
use Illuminate\Console\Command;

class SyncInvoicesToQuickBooks extends Command
{
    protected $signature = 'qbo:sync-invoices
                            {--invoices : Sync pending invoices}
                            {--payments : Sync pending payments}
                            {--all : Sync both invoices and payments}
                            {--limit=50 : Maximum items to sync}';

    protected $description = 'Sync pending invoices and payments to QuickBooks';

    public function handle(QuickBooksInvoiceSyncService $syncService): int
    {
        $syncInvoices = $this->option('invoices') || $this->option('all');
        $syncPayments = $this->option('payments') || $this->option('all');
        $limit = (int) $this->option('limit');

        // Default to syncing both if no specific option given
        if (! $syncInvoices && ! $syncPayments) {
            $syncInvoices = true;
            $syncPayments = true;
        }

        if ($syncInvoices) {
            $this->info('Syncing pending invoices...');
            $results = $syncService->syncPendingInvoices($limit);
            $this->info("  Synced: {$results['synced']}, Failed: {$results['failed']}");

            foreach ($results['errors'] as $error) {
                $this->error("  Invoice {$error['invoice_id']}: {$error['error']}");
            }
        }

        if ($syncPayments) {
            $this->info('Syncing pending payments...');
            $results = $syncService->syncPendingPayments($limit);
            $this->info("  Synced: {$results['synced']}, Failed: {$results['failed']}");

            foreach ($results['errors'] as $error) {
                $this->error("  Payment {$error['payment_id']}: {$error['error']}");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
