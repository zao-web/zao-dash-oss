<?php

namespace App\Services\QuickBooks;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\QuickBooksConnection;
use Illuminate\Support\Facades\Log;

class QuickBooksInvoiceSyncService
{
    public function __construct(
        protected QuickBooksApiService $api
    ) {}

    /**
     * Get active QBO connection (assumes single-company setup).
     */
    public function getConnection(): ?QuickBooksConnection
    {
        return QuickBooksConnection::where('is_active', true)->first();
    }

    /**
     * Sync invoice to QuickBooks.
     */
    public function syncInvoice(Invoice $invoice): ?string
    {
        $connection = $this->getConnection();
        if (! $connection) {
            Log::info('QBO sync skipped: no active connection', ['invoice_id' => $invoice->id]);

            return null;
        }

        // Skip drafts
        if ($invoice->status === Invoice::STATUS_DRAFT) {
            return null;
        }

        // Already synced?
        if ($invoice->qbo_invoice_id) {
            Log::info('QBO invoice already synced', [
                'invoice_id' => $invoice->id,
                'qbo_invoice_id' => $invoice->qbo_invoice_id,
            ]);

            return $invoice->qbo_invoice_id;
        }

        try {
            // Ensure client has QBO customer
            $customerId = $this->ensureCustomer($connection, $invoice->client);
            if (! $customerId) {
                throw new \Exception('Failed to get or create QBO customer');
            }

            // Build invoice line items
            $lineItems = [];
            foreach ($invoice->lines as $line) {
                $lineItems[] = [
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'amount' => (float) $line->amount,
                ];
            }

            // Create in QBO
            $qboInvoice = $this->api->createInvoice($connection, [
                'customer_id' => $customerId,
                'line_items' => $lineItems,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'txn_date' => $invoice->issue_date->format('Y-m-d'),
                'customer_memo' => $invoice->notes,
                'private_note' => "Zao Invoice #{$invoice->number}",
                'billing_email' => $invoice->client->billing_email ?? $invoice->client->email,
            ]);

            $qboInvoiceId = $qboInvoice['Id'] ?? null;

            if ($qboInvoiceId) {
                $invoice->update([
                    'qbo_invoice_id' => $qboInvoiceId,
                    'qbo_synced_at' => now(),
                ]);

                Log::info('QBO invoice created', [
                    'invoice_id' => $invoice->id,
                    'qbo_invoice_id' => $qboInvoiceId,
                ]);
            }

            return $qboInvoiceId;

        } catch (\Exception $e) {
            Log::error('QBO invoice sync failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync payment to QuickBooks.
     */
    public function syncPayment(Payment $payment): ?string
    {
        $connection = $this->getConnection();
        if (! $connection) {
            Log::info('QBO sync skipped: no active connection', ['payment_id' => $payment->id]);

            return null;
        }

        // Only sync completed payments
        if ($payment->status !== Payment::STATUS_COMPLETED) {
            return null;
        }

        // Already synced?
        if ($payment->qbo_payment_id) {
            return $payment->qbo_payment_id;
        }

        $invoice = $payment->invoice;
        if (! $invoice) {
            return null;
        }

        try {
            // Ensure invoice is synced first
            if (! $invoice->qbo_invoice_id) {
                $this->syncInvoice($invoice);
                $invoice->refresh();
            }

            if (! $invoice->qbo_invoice_id) {
                throw new \Exception('Cannot sync payment without synced invoice');
            }

            // Get customer ID
            $customerId = $invoice->client->qbo_customer_id;
            if (! $customerId) {
                $customerId = $this->ensureCustomer($connection, $invoice->client);
            }

            // Record payment in QBO
            $qboPayment = $this->api->recordPayment($connection, [
                'customer_id' => $customerId,
                'amount' => (float) $payment->amount,
                'invoice_id' => $invoice->qbo_invoice_id,
                'txn_date' => $payment->payment_date->format('Y-m-d'),
                'private_note' => $this->buildPaymentNote($payment),
            ]);

            $qboPaymentId = $qboPayment['Id'] ?? null;

            if ($qboPaymentId) {
                $payment->update([
                    'qbo_payment_id' => $qboPaymentId,
                    'qbo_synced_at' => now(),
                ]);

                Log::info('QBO payment created', [
                    'payment_id' => $payment->id,
                    'qbo_payment_id' => $qboPaymentId,
                ]);
            }

            return $qboPaymentId;

        } catch (\Exception $e) {
            Log::error('QBO payment sync failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Ensure client has a QBO customer, creating if needed.
     */
    protected function ensureCustomer(QuickBooksConnection $connection, Client $client): ?string
    {
        if ($client->qbo_customer_id) {
            return $client->qbo_customer_id;
        }

        try {
            $qboCustomer = $this->api->createCustomer($connection, [
                'display_name' => $client->name,
                'company_name' => $client->company_name ?? $client->name,
                'email' => $client->billing_email ?? $client->email,
                'phone' => $client->phone,
                'notes' => "Synced from Zao - Client #{$client->id}",
            ]);

            $customerId = $qboCustomer['Id'] ?? null;

            if ($customerId) {
                $client->update(['qbo_customer_id' => $customerId]);
                Log::info('QBO customer created', [
                    'client_id' => $client->id,
                    'qbo_customer_id' => $customerId,
                ]);
            }

            return $customerId;

        } catch (\Exception $e) {
            Log::error('QBO customer creation failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a note for the payment in QBO.
     */
    protected function buildPaymentNote(Payment $payment): string
    {
        $parts = [
            "Zao Invoice #{$payment->invoice->number}",
            "Method: {$payment->method_label}",
        ];

        if ($payment->transaction_id) {
            $parts[] = "Transaction: {$payment->transaction_id}";
        }

        if ($payment->reference) {
            $parts[] = "Ref: {$payment->reference}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Sync all unsynced invoices.
     */
    public function syncPendingInvoices(int $limit = 50): array
    {
        $results = ['synced' => 0, 'failed' => 0, 'errors' => []];

        $invoices = Invoice::whereNull('qbo_invoice_id')
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->limit($limit)
            ->get();

        foreach ($invoices as $invoice) {
            try {
                $this->syncInvoice($invoice);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Sync all unsynced payments.
     */
    public function syncPendingPayments(int $limit = 50): array
    {
        $results = ['synced' => 0, 'failed' => 0, 'errors' => []];

        $payments = Payment::whereNull('qbo_payment_id')
            ->where('status', Payment::STATUS_COMPLETED)
            ->limit($limit)
            ->get();

        foreach ($payments as $payment) {
            try {
                $this->syncPayment($payment);
                $results['synced']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
