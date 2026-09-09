<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Services\PayPal\PayPalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPayPalInvoiceStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PayPalService $paypalService): void
    {
        if (! $paypalService->isConfigured()) {
            return;
        }

        // Find all open invoices with a PayPal ID
        $invoices = Invoice::whereNotNull('paypal_invoice_id')
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_VIEWED,
                Invoice::STATUS_PARTIAL,
                Invoice::STATUS_OVERDUE,
            ])
            ->get();

        $synced = 0;

        foreach ($invoices as $invoice) {
            try {
                $result = $paypalService->checkPaymentStatus($invoice);
                $paypalStatus = $result['status'] ?? 'unknown';

                if ($paypalStatus === 'PAID' && $invoice->status !== Invoice::STATUS_PAID) {
                    // PayPal says paid but we didn't know — record the payment
                    $payments = $result['payments'] ?? [];

                    if (! empty($payments)) {
                        // Only record payments we don't already have
                        $existingTxIds = $invoice->payments()->pluck('transaction_id')->toArray();

                        foreach ($payments as $paymentData) {
                            $txId = $paymentData['payment_id'] ?? null;
                            if ($txId && in_array($txId, $existingTxIds)) {
                                continue;
                            }

                            $paypalService->recordPayment($invoice, $paymentData);
                            $synced++;
                        }
                    } else {
                        // No transaction details, record full amount
                        $paypalService->recordPayment($invoice, [
                            'amount' => ['value' => $invoice->amount_due],
                        ]);
                        $synced++;
                    }

                    // Cancel pending reminders
                    $invoice->reminders()
                        ->where('status', InvoiceReminder::STATUS_PENDING)
                        ->update(['status' => InvoiceReminder::STATUS_CANCELLED]);

                    Log::info('PayPal sync: invoice marked as paid', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->number,
                    ]);
                } elseif ($paypalStatus === 'MARKED_AS_PAID' && $invoice->status !== Invoice::STATUS_PAID) {
                    // Manually marked as paid in PayPal
                    $paypalService->recordPayment($invoice, [
                        'amount' => ['value' => $invoice->amount_due],
                    ]);

                    $invoice->reminders()
                        ->where('status', InvoiceReminder::STATUS_PENDING)
                        ->update(['status' => InvoiceReminder::STATUS_CANCELLED]);

                    $synced++;

                    Log::info('PayPal sync: invoice manually marked as paid', [
                        'invoice_id' => $invoice->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('PayPal sync failed for invoice', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($synced > 0) {
            Log::info("PayPal sync completed: {$synced} payment(s) recorded");
        }
    }
}
