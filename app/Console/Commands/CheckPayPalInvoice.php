<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckPayPalInvoice extends Command
{
    protected $signature = 'paypal:check {invoice : Invoice ID or number}';

    protected $description = 'Check PayPal invoice status and get payment link';

    public function handle(PayPalService $paypalService): int
    {
        $input = $this->argument('invoice');

        $invoice = is_numeric($input)
            ? Invoice::find($input)
            : Invoice::where('number', $input)->first();

        if (! $invoice) {
            $this->error("Invoice not found: {$input}");

            return 1;
        }

        $this->info("Invoice #{$invoice->number} (ID: {$invoice->id})");
        $this->line("Status: {$invoice->status}");
        $this->line("Amount Due: \${$invoice->amount_due}");
        $this->line('PayPal Invoice ID: '.($invoice->paypal_invoice_id ?: 'Not set'));

        if (! $paypalService->isConfigured()) {
            $this->error('PayPal is not configured');

            return 1;
        }

        $this->info('PayPal is configured');

        if (! $invoice->paypal_invoice_id) {
            $this->warn('No PayPal invoice ID - would need to create one');

            return 0;
        }

        // Fetch invoice from PayPal API
        $this->line('');
        $this->info('Fetching from PayPal API...');

        try {
            $token = $paypalService->getAccessToken();
            $baseUrl = config('services.paypal.mode') === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $response = Http::withToken($token)
                ->get("{$baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}");

            if (! $response->successful()) {
                $this->error("PayPal API error: {$response->status()}");
                $this->line($response->body());

                return 1;
            }

            $paypalInvoice = $response->json();

            $this->table(['Field', 'Value'], [
                ['PayPal ID', $paypalInvoice['id'] ?? 'N/A'],
                ['Status', $paypalInvoice['status'] ?? 'N/A'],
                ['Invoice Number', $paypalInvoice['detail']['invoice_number'] ?? 'N/A'],
                ['Recipient Email', $paypalInvoice['primary_recipients'][0]['billing_info']['email_address'] ?? 'N/A'],
            ]);

            $paymentLink = $paypalInvoice['detail']['metadata']['recipient_view_url'] ?? null;

            if ($paymentLink) {
                $this->info("Payment Link: {$paymentLink}");
            } else {
                $this->warn('No payment link available');
                $this->line("Invoice status is: {$paypalInvoice['status']}");

                if (($paypalInvoice['status'] ?? '') === 'DRAFT') {
                    $this->warn('Invoice is in DRAFT status - needs to be sent to get payment link');

                    if ($this->confirm('Send the invoice now?')) {
                        $sendResponse = Http::withToken($token)
                            ->post("{$baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}/send", [
                                'send_to_invoicer' => false,
                                'send_to_recipient' => false,
                            ]);

                        if ($sendResponse->successful()) {
                            $this->info('Invoice sent! Fetching payment link...');

                            // Fetch again to get the link
                            $refreshResponse = Http::withToken($token)
                                ->get("{$baseUrl}/v2/invoicing/invoices/{$invoice->paypal_invoice_id}");

                            if ($refreshResponse->successful()) {
                                $refreshedInvoice = $refreshResponse->json();
                                $newLink = $refreshedInvoice['detail']['metadata']['recipient_view_url'] ?? null;
                                if ($newLink) {
                                    $this->info("Payment Link: {$newLink}");
                                }
                            }
                        } else {
                            $this->error("Failed to send: {$sendResponse->body()}");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return 1;
        }

        return 0;
    }
}
