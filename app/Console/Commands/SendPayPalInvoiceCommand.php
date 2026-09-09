<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Illuminate\Console\Command;

class SendPayPalInvoiceCommand extends Command
{
    protected $signature = 'paypal:send-invoice {invoice_id} {--no-email : Mark as sent without emailing recipient}';

    protected $description = 'Send a PayPal invoice to activate payment link';

    public function handle(PayPalService $paypalService): int
    {
        $invoiceId = $this->argument('invoice_id');
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            $this->error("Invoice #{$invoiceId} not found");

            return 1;
        }

        if (! $invoice->paypal_invoice_id) {
            $this->error('Invoice has no PayPal invoice ID');
            $this->info("Run: php artisan paypal:find-invoice {$invoiceId}");

            return 1;
        }

        $sendEmail = ! $this->option('no-email');

        if ($sendEmail) {
            $this->info("Sending PayPal invoice {$invoice->paypal_invoice_id} (will email recipient)...");
        } else {
            $this->info("Marking PayPal invoice {$invoice->paypal_invoice_id} as sent (no email)...");
        }

        try {
            $paypalService->sendInvoice($invoice, $sendEmail);
            $this->info('✓ PayPal invoice sent successfully!');

            sleep(2);

            $paymentLink = $paypalService->getPaymentLink($invoice);

            if ($paymentLink) {
                $this->info("Payment link: {$paymentLink}");
            } else {
                $this->warn('Payment link not yet available. Try again in a few seconds.');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to send PayPal invoice:');
            $this->error("  {$e->getMessage()}");

            return 1;
        }
    }
}
