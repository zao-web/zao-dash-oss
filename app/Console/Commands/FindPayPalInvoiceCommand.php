<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Illuminate\Console\Command;

class FindPayPalInvoiceCommand extends Command
{
    protected $signature = 'paypal:find-invoice {invoice_id}';

    protected $description = 'Find a PayPal invoice by invoice number and link it to local record';

    public function handle(PayPalService $paypalService): int
    {
        $invoiceId = $this->argument('invoice_id');
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            $this->error("Invoice #{$invoiceId} not found");

            return 1;
        }

        if ($invoice->paypal_invoice_id) {
            $this->info("Invoice already has PayPal ID: {$invoice->paypal_invoice_id}");

            return 0;
        }

        $this->info("Searching PayPal for invoice number: {$invoice->number}");

        try {
            $token = $paypalService->getAccessToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get(config('services.paypal.mode') === 'live'
                    ? 'https://api-m.paypal.com/v2/invoicing/invoices'
                    : 'https://api-m.sandbox.paypal.com/v2/invoicing/invoices', [
                        'invoice_number' => $invoice->number,
                        'page_size' => 100,
                    ]);

            if (! $response->successful()) {
                $this->error("PayPal API error: {$response->status()} - {$response->body()}");

                return 1;
            }

            $data = $response->json();
            $invoices = $data['items'] ?? [];

            $this->info('Found '.count($invoices).' invoice(s)');

            foreach ($invoices as $paypalInvoice) {
                if ($paypalInvoice['detail']['invoice_number'] === $invoice->number) {
                    $paypalId = $paypalInvoice['id'];
                    $status = $paypalInvoice['status'] ?? 'unknown';

                    $this->info('Found matching PayPal invoice:');
                    $this->line("  PayPal ID: {$paypalId}");
                    $this->line("  Status: {$status}");

                    if ($this->confirm('Link this PayPal invoice to local record?', true)) {
                        $invoice->update(['paypal_invoice_id' => $paypalId]);
                        $this->info('✓ Successfully linked!');

                        $paymentLink = $paypalService->getPaymentLink($invoice);
                        if ($paymentLink) {
                            $this->info("Payment link: {$paymentLink}");
                        }

                        return 0;
                    }
                }
            }

            $this->warn("No matching PayPal invoice found for number: {$invoice->number}");
            $this->info('You may need to:');
            $this->line('  1. Cancel the duplicate PayPal invoice');
            $this->line('  2. Use a different invoice number');
            $this->line('  3. Manually update the paypal_invoice_id in the database');

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return 1;
        }

        return 0;
    }
}
