<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckPayPalInvoiceCommand extends Command
{
    protected $signature = 'paypal:check-invoice {invoice_id}';

    protected $description = 'Check PayPal invoice details via API';

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

            return 1;
        }

        $this->info("Fetching PayPal invoice: {$invoice->paypal_invoice_id}");

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

            $data = $response->json();

            $this->info('=== PayPal Invoice Details ===');
            $this->line("ID: {$data['id']}");
            $this->line("Status: {$data['status']}");
            $this->line('Invoice Number: '.($data['detail']['invoice_number'] ?? 'N/A'));

            $this->newLine();
            $this->info('=== Links ===');
            foreach ($data['links'] ?? [] as $link) {
                $this->line("{$link['rel']}: {$link['href']}");
            }

            $payerLink = collect($data['links'] ?? [])->firstWhere('rel', 'payer-view');

            $this->newLine();
            if ($payerLink) {
                $this->info('✓ Payment Link:');
                $this->line($payerLink['href']);
            } else {
                $this->warn('✗ No payer-view link found');
                $this->info('Available link types: '.collect($data['links'] ?? [])->pluck('rel')->join(', '));

                if ($data['status'] === 'DRAFT') {
                    $this->warn("Invoice is in DRAFT status. Run: php artisan paypal:send-invoice {$invoiceId} --no-email");
                }
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");

            return 1;
        }
    }
}
