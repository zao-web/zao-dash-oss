<?php

namespace App\Http\Controllers;

use App\Jobs\SyncQuickBooksJob;
use App\Models\QboInvoice;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QuickBooksWebhookController extends Controller
{
    /**
     * Handle incoming QuickBooks webhook notifications.
     *
     * QuickBooks sends webhooks for various events:
     * - Invoice created/updated/deleted
     * - Payment created/updated
     * - Customer created/updated
     * - Account changes
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Verify webhook signature
        if (! $this->verifySignature($request)) {
            Log::warning('QuickBooks webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        Log::info('QuickBooks webhook received', ['payload' => $payload]);

        // QuickBooks sends notifications in eventNotifications array
        $notifications = $payload['eventNotifications'] ?? [];

        foreach ($notifications as $notification) {
            $realmId = $notification['realmId'] ?? null;
            $dataChangeEvent = $notification['dataChangeEvent'] ?? null;

            if (! $realmId || ! $dataChangeEvent) {
                continue;
            }

            // Find the connection for this realm
            $connection = QuickBooksConnection::where('realm_id', $realmId)->first();

            if (! $connection) {
                Log::warning('QuickBooks webhook for unknown realm', ['realm_id' => $realmId]);

                continue;
            }

            $entities = $dataChangeEvent['entities'] ?? [];

            foreach ($entities as $entity) {
                $this->processEntity($connection, $entity);
            }
        }

        // Return 200 OK to acknowledge receipt
        return response()->json(['status' => 'ok']);
    }

    /**
     * Process a single entity change notification.
     */
    protected function processEntity(QuickBooksConnection $connection, array $entity): void
    {
        $entityName = $entity['name'] ?? null;
        $entityId = $entity['id'] ?? null;
        $operation = $entity['operation'] ?? null;

        if (! $entityName || ! $entityId) {
            return;
        }

        Log::info('Processing QuickBooks entity', [
            'entity' => $entityName,
            'id' => $entityId,
            'operation' => $operation,
        ]);

        match ($entityName) {
            'Invoice' => $this->handleInvoiceChange($connection, $entityId, $operation),
            'Payment' => $this->handlePaymentChange($connection, $entityId, $operation),
            'Customer' => $this->handleCustomerChange($connection, $entityId, $operation),
            'Purchase' => $this->handlePurchaseChange($connection, $entityId, $operation),
            'Bill' => $this->handleBillChange($connection, $entityId, $operation),
            default => Log::info("Unhandled QuickBooks entity type: {$entityName}"),
        };
    }

    /**
     * Handle invoice create/update/delete.
     */
    protected function handleInvoiceChange(QuickBooksConnection $connection, string $entityId, string $operation): void
    {
        if ($operation === 'Delete') {
            QboInvoice::where('qbo_invoice_id', $entityId)
                ->where('quickbooks_connection_id', $connection->id)
                ->delete();

            return;
        }

        // Trigger a sync job to fetch the updated invoice
        SyncQuickBooksJob::dispatch($connection->user_id)
            ->onQueue('integrations');
    }

    /**
     * Handle payment create/update.
     */
    protected function handlePaymentChange(QuickBooksConnection $connection, string $entityId, string $operation): void
    {
        // Payments affect invoice balances, trigger sync
        SyncQuickBooksJob::dispatch($connection->user_id)
            ->onQueue('integrations');

        // Could also trigger notification for payment received
        if ($operation === 'Create') {
            Log::info('New QuickBooks payment received', [
                'payment_id' => $entityId,
                'connection_id' => $connection->id,
            ]);
        }
    }

    /**
     * Handle customer create/update.
     */
    protected function handleCustomerChange(QuickBooksConnection $connection, string $entityId, string $operation): void
    {
        // Customers sync needed to match invoices
        SyncQuickBooksJob::dispatch($connection->user_id)
            ->onQueue('integrations');
    }

    /**
     * Handle purchase/expense create/update.
     */
    protected function handlePurchaseChange(QuickBooksConnection $connection, string $entityId, string $operation): void
    {
        if ($operation === 'Delete') {
            QboTransaction::where('qbo_transaction_id', $entityId)
                ->where('quickbooks_connection_id', $connection->id)
                ->delete();

            return;
        }

        // New expense - BookkeepingAgent may need to categorize
        SyncQuickBooksJob::dispatch($connection->user_id)
            ->onQueue('integrations');
    }

    /**
     * Handle bill create/update.
     */
    protected function handleBillChange(QuickBooksConnection $connection, string $entityId, string $operation): void
    {
        SyncQuickBooksJob::dispatch($connection->user_id)
            ->onQueue('integrations');
    }

    /**
     * Verify the webhook signature from QuickBooks.
     */
    protected function verifySignature(Request $request): bool
    {
        $signature = $request->header('intuit-signature');
        $webhookVerifierToken = config('services.quickbooks.webhook_verifier_token');

        if (! $signature || ! $webhookVerifierToken) {
            // If no verifier token configured, skip verification (dev mode)
            return empty($webhookVerifierToken);
        }

        $payload = $request->getContent();
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $webhookVerifierToken, true));

        return hash_equals($expectedSignature, $signature);
    }
}
