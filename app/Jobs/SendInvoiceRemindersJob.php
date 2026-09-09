<?php

namespace App\Jobs;

use App\Mail\InvoiceReminderMail;
use App\Models\InvoiceReminder;
use App\Services\PayPal\PayPalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvoiceRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        //
    }

    public function handle(PayPalService $paypalService): void
    {
        $dueReminders = InvoiceReminder::where('status', InvoiceReminder::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())
            ->with(['invoice.client'])
            ->get();

        foreach ($dueReminders as $reminder) {
            $this->sendReminder($reminder, $paypalService);
        }

        Log::info('Invoice reminders processed', ['count' => $dueReminders->count()]);
    }

    protected function sendReminder(InvoiceReminder $reminder, PayPalService $paypalService): void
    {
        $invoice = $reminder->invoice;

        // Skip if invoice is now paid or cancelled
        if ($invoice->isPaid() || $invoice->status === 'cancelled') {
            $reminder->cancel();

            return;
        }

        // Get recipient email
        $recipientEmail = $invoice->client->billing_email
            ?? $invoice->client->contacts()->first()?->email;

        if (! $recipientEmail) {
            $reminder->markFailed('No recipient email found');

            return;
        }

        try {
            // Get PayPal payment link if available
            $paymentLink = null;
            if ($invoice->paypal_invoice_id) {
                $paymentLink = $paypalService->getPaymentLink($invoice);
            }

            Mail::to($recipientEmail)
                ->cc($invoice->client->billingCcList())
                ->send(new InvoiceReminderMail($invoice, $reminder, $paymentLink));

            $reminder->markSent();

            Log::info('Invoice reminder sent', [
                'invoice_id' => $invoice->id,
                'reminder_type' => $reminder->type,
                'recipient' => $recipientEmail,
            ]);
        } catch (\Exception $e) {
            $reminder->markFailed($e->getMessage());

            Log::error('Failed to send invoice reminder', [
                'invoice_id' => $invoice->id,
                'reminder_id' => $reminder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
