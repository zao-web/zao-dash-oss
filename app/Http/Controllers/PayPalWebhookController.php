<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Services\Invoicing\InvoiceService;
use App\Services\PayPal\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function __construct(
        protected PayPalService $paypalService,
        protected InvoiceService $invoiceService
    ) {}

    public function handle(Request $request): Response
    {
        $headers = $request->headers->all();
        $body = $request->getContent();

        // Flatten headers (Laravel returns arrays)
        $flatHeaders = array_map(fn ($h) => is_array($h) ? $h[0] : $h, $headers);

        // Verify webhook signature when webhook_id is configured
        if (config('services.paypal.webhook_id')) {
            if (! $this->paypalService->verifyWebhookSignature($flatHeaders, $body)) {
                Log::warning('PayPal webhook signature verification failed');

                return response('Invalid signature', 401);
            }
        } else {
            Log::info('PayPal webhook received without signature verification (PAYPAL_WEBHOOK_ID not configured)');
        }

        $event = json_decode($body, true);
        $eventType = $event['event_type'] ?? null;

        Log::info('PayPal webhook received', [
            'event_type' => $eventType,
            'resource_type' => $event['resource_type'] ?? null,
        ]);

        return match ($eventType) {
            'INVOICING.INVOICE.PAID' => $this->handleInvoicePaid($event),
            'INVOICING.INVOICE.PARTIALLY_PAID' => $this->handleInvoicePartiallyPaid($event),
            'INVOICING.INVOICE.CANCELLED' => $this->handleInvoiceCancelled($event),
            'INVOICING.INVOICE.REFUNDED' => $this->handleInvoiceRefunded($event),
            'PAYMENT.CAPTURE.COMPLETED' => $this->handlePaymentCompleted($event),
            default => response('Event type not handled', 200),
        };
    }

    protected function handleInvoicePaid(array $event): Response
    {
        $paypalInvoiceId = $event['resource']['id'] ?? null;

        if (! $paypalInvoiceId) {
            Log::warning('PayPal invoice paid event missing invoice ID');

            return response('Missing invoice ID', 400);
        }

        $invoice = Invoice::where('paypal_invoice_id', $paypalInvoiceId)->first();

        if (! $invoice) {
            Log::warning('PayPal invoice not found in system', [
                'paypal_invoice_id' => $paypalInvoiceId,
            ]);

            return response('Invoice not found', 404);
        }

        // Record the payment
        $paymentData = $event['resource']['payments']['transactions'][0] ?? [];
        $this->paypalService->recordPayment($invoice, $paymentData);

        // Cancel pending reminders
        $invoice->reminders()
            ->where('status', InvoiceReminder::STATUS_PENDING)
            ->update(['status' => InvoiceReminder::STATUS_CANCELLED]);

        Log::info('PayPal invoice marked as paid', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
        ]);

        return response('OK', 200);
    }

    protected function handleInvoicePartiallyPaid(array $event): Response
    {
        $paypalInvoiceId = $event['resource']['id'] ?? null;

        if (! $paypalInvoiceId) {
            return response('Missing invoice ID', 400);
        }

        $invoice = Invoice::where('paypal_invoice_id', $paypalInvoiceId)->first();

        if (! $invoice) {
            return response('Invoice not found', 404);
        }

        // Record the partial payment
        $payments = $event['resource']['payments']['transactions'] ?? [];
        $latestPayment = end($payments);

        if ($latestPayment) {
            $this->paypalService->recordPayment($invoice, $latestPayment);
        }

        Log::info('PayPal invoice partially paid', [
            'invoice_id' => $invoice->id,
            'amount_paid' => $invoice->fresh()->amount_paid,
        ]);

        return response('OK', 200);
    }

    protected function handleInvoiceCancelled(array $event): Response
    {
        $paypalInvoiceId = $event['resource']['id'] ?? null;

        if (! $paypalInvoiceId) {
            return response('Missing invoice ID', 400);
        }

        $invoice = Invoice::where('paypal_invoice_id', $paypalInvoiceId)->first();

        if ($invoice) {
            $this->invoiceService->cancel($invoice, 'Cancelled via PayPal');
        }

        return response('OK', 200);
    }

    protected function handleInvoiceRefunded(array $event): Response
    {
        $paypalInvoiceId = $event['resource']['id'] ?? null;

        if (! $paypalInvoiceId) {
            return response('Missing invoice ID', 400);
        }

        $invoice = Invoice::where('paypal_invoice_id', $paypalInvoiceId)->first();

        if (! $invoice) {
            return response('Invoice not found', 404);
        }

        // Find the related payment and mark as refunded
        $refundData = $event['resource']['refunds']['transactions'][0] ?? [];
        $transactionId = $refundData['refund_from_transaction_id'] ?? null;

        if ($transactionId) {
            $invoice->payments()
                ->where('transaction_id', $transactionId)
                ->update(['status' => 'refunded']);

            // Recalculate totals
            $invoice->recalculateTotals();
        }

        Log::info('PayPal invoice refunded', [
            'invoice_id' => $invoice->id,
            'refund_amount' => $refundData['amount']['value'] ?? 'unknown',
        ]);

        return response('OK', 200);
    }

    protected function handlePaymentCompleted(array $event): Response
    {
        // This is for direct PayPal checkout (not invoicing)
        $customId = $event['resource']['custom_id'] ?? null;

        if (! $customId) {
            return response('OK', 200);
        }

        // Try to match by invoice number in custom_id
        $invoice = Invoice::where('number', $customId)->first();

        if ($invoice) {
            $this->paypalService->recordPayment($invoice, [
                'payment_id' => $event['resource']['id'],
                'amount' => $event['resource']['amount'],
            ]);
        }

        return response('OK', 200);
    }
}
