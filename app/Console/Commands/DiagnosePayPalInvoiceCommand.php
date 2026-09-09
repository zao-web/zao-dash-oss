<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Illuminate\Console\Command;

class DiagnosePayPalInvoiceCommand extends Command
{
    protected $signature = 'paypal:diagnose {invoice_id}';

    protected $description = 'Diagnose PayPal integration for a specific invoice';

    public function handle(PayPalService $paypalService): int
    {
        $invoiceId = $this->argument('invoice_id');
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            $this->error("Invoice #{$invoiceId} not found");

            return 1;
        }

        $this->info('=== Invoice Diagnosis ===');
        $this->line("Invoice ID: {$invoice->id}");
        $this->line("Invoice Number: {$invoice->number}");
        $this->line("Status: {$invoice->status}");
        $this->line("Amount Due: \${$invoice->amount_due}");
        $this->line('PayPal Invoice ID: '.($invoice->paypal_invoice_id ?? 'NULL'));

        $this->newLine();
        $this->info('=== PayPal Configuration ===');
        $this->line('Client ID: '.(config('services.paypal.client_id') ? 'SET' : 'NOT SET'));
        $this->line('Client Secret: '.(config('services.paypal.client_secret') ? 'SET' : 'NOT SET'));
        $this->line('Mode: '.config('services.paypal.mode'));
        $this->line('Is Configured: '.($paypalService->isConfigured() ? 'YES' : 'NO'));

        $this->newLine();
        $this->info('=== Line Items ===');
        $invoice->load('lines');
        $this->line("Total Lines: {$invoice->lines->count()}");

        foreach ($invoice->lines as $line) {
            $this->line("  - {$line->description}: qty={$line->quantity}, price={$line->unit_price}, amount={$line->amount}");
            if ($line->quantity <= 0) {
                $this->warn('    ^ This line has negative/zero quantity (will be skipped by PayPal)');
            }
        }

        if (! $paypalService->isConfigured()) {
            $this->error('PayPal is not configured. Cannot proceed.');

            return 1;
        }

        $this->newLine();

        if (! $invoice->paypal_invoice_id) {
            $this->info('=== Creating PayPal Invoice ===');

            try {
                $this->line('Attempting to create PayPal invoice...');
                $paypalService->createInvoice($invoice);
                $invoice->refresh();

                $this->info('✓ PayPal invoice created successfully!');
                $this->line("PayPal Invoice ID: {$invoice->paypal_invoice_id}");
            } catch (\Exception $e) {
                $this->error('✗ Failed to create PayPal invoice:');
                $this->error("  {$e->getMessage()}");

                if ($this->option('verbose')) {
                    $this->line($e->getTraceAsString());
                }

                return 1;
            }
        } else {
            $this->info("PayPal invoice already exists: {$invoice->paypal_invoice_id}");
        }

        $this->newLine();
        $this->info('=== Getting Payment Link ===');

        try {
            $this->line('Fetching payment link from PayPal API...');
            $paymentLink = $paypalService->getPaymentLink($invoice);

            if ($paymentLink) {
                $this->info('✓ Payment link retrieved successfully:');
                $this->line("  {$paymentLink}");
            } else {
                $this->error('✗ Payment link is NULL');
                $this->warn('This could mean:');
                $this->warn('  1. PayPal API call failed');
                $this->warn("  2. PayPal invoice doesn't have a 'payer-view' link");
                $this->warn("  3. Invoice status doesn't allow payment");
            }
        } catch (\Exception $e) {
            $this->error('✗ Exception while getting payment link:');
            $this->error("  {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
        }

        $this->newLine();
        $this->info('=== PayPal Status Check ===');

        try {
            $status = $paypalService->checkPaymentStatus($invoice);
            $this->line('PayPal Status: '.($status['status'] ?? 'unknown'));

            if (isset($status['amount_paid'])) {
                $this->line("Amount Paid: \${$status['amount_paid']}");
            }
        } catch (\Exception $e) {
            $this->warn("Could not check PayPal status: {$e->getMessage()}");
        }

        return 0;
    }
}
