<?php

use App\Models\Invoice;
use App\Services\Invoicing\PdfInvoiceGenerator;
use App\Services\PayPal\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('renders pdf html with dynamic paypal payment url', function () {
    // Create an invoice with a PayPal invoice ID
    $invoice = Invoice::factory()->withPayPal()->create();

    // Mock PayPalService to return a sandbox URL
    $mockPayPalService = Mockery::mock(PayPalService::class);
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(true);
    $mockPayPalService->shouldReceive('getPaymentLink')
        ->with(Mockery::on(fn ($arg) => $arg->id === $invoice->id))
        ->andReturn('https://www.sandbox.paypal.com/invoice/p/#'.$invoice->paypal_invoice_id);

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // The HTML should contain the sandbox URL, not the hardcoded production URL
    expect($html)->toContain('https://www.sandbox.paypal.com/invoice/p/#'.$invoice->paypal_invoice_id);
    expect($html)->not->toContain('https://www.paypal.com/invoice/p/#');
});

test('renders pdf html without paypal link when paypal not configured', function () {
    $invoice = Invoice::factory()->withPayPal()->create();

    $mockPayPalService = Mockery::mock(PayPalService::class);
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(false);
    // getPaymentLink should not be called when not configured

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // Should not contain any PayPal link when not configured
    expect($html)->not->toContain('Pay $');
    expect($html)->not->toContain('paypal.com');
});

test('renders pdf html without paypal link when paypal not configured and no paypal id', function () {
    // Create invoice without PayPal ID
    $invoice = Invoice::factory()->create(['paypal_invoice_id' => null]);

    $mockPayPalService = Mockery::mock(PayPalService::class);
    // PayPal not configured, so no attempt to create invoice
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(false);

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // Should render but without PayPal payment option
    expect($html)->not->toContain('paypal.com');
});

test('auto-creates paypal invoice when configured but invoice has no paypal id', function () {
    // Create invoice without PayPal ID
    $invoice = Invoice::factory()->create([
        'paypal_invoice_id' => null,
        'amount_due' => 100.00,
    ]);

    $mockPayPalService = Mockery::mock(PayPalService::class);
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(true);

    // Should attempt to create PayPal invoice
    $mockPayPalService->shouldReceive('createInvoice')
        ->once()
        ->with(Mockery::on(fn ($arg) => $arg->id === $invoice->id))
        ->andReturnUsing(function ($inv) {
            $inv->paypal_invoice_id = 'INV2-AUTO-CREATED-TEST';
            $inv->save();

            return ['id' => 'INV2-AUTO-CREATED-TEST'];
        });

    // After auto-creation, should get payment link
    $mockPayPalService->shouldReceive('getPaymentLink')
        ->once()
        ->andReturn('https://www.sandbox.paypal.com/invoice/p/#INV2-AUTO-CREATED-TEST');

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // Should contain the PayPal link after auto-creation
    expect($html)->toContain('sandbox.paypal.com');
});

test('renders pdf html with production paypal url when in production mode', function () {
    $invoice = Invoice::factory()->withPayPal()->create();

    // Mock PayPalService to return a production URL
    $mockPayPalService = Mockery::mock(PayPalService::class);
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(true);
    $mockPayPalService->shouldReceive('getPaymentLink')
        ->with(Mockery::on(fn ($arg) => $arg->id === $invoice->id))
        ->andReturn('https://www.paypal.com/invoice/p/#'.$invoice->paypal_invoice_id);

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // Should contain the production URL
    expect($html)->toContain('https://www.paypal.com/invoice/p/#'.$invoice->paypal_invoice_id);
});

test('handles null payment url gracefully', function () {
    $invoice = Invoice::factory()->withPayPal()->create();

    // PayPal API might fail to return a URL
    $mockPayPalService = Mockery::mock(PayPalService::class);
    $mockPayPalService->shouldReceive('isConfigured')->andReturn(true);
    $mockPayPalService->shouldReceive('getPaymentLink')
        ->andReturn(null);

    $generator = new PdfInvoiceGenerator($mockPayPalService);

    $html = $generator->renderHtml($invoice);

    // Should render successfully without the PayPal payment option
    expect($html)->toContain('Invoice #');
    expect($html)->not->toContain('Pay $');
    expect($html)->not->toContain('paypal.com');
});
