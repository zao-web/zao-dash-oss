<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\PayPal\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.paypal.client_id' => 'test-client-id',
        'services.paypal.client_secret' => 'test-secret',
        'services.paypal.mode' => 'sandbox',
        'services.paypal.invoicer_email' => 'invoicer@example.com',
    ]);
});

it('updates a mismatched DRAFT duplicate PayPal invoice with current data before linking', function () {
    $client = Client::factory()->create(['billing_email' => 'client@example.com']);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => 'INV-2000',
        'total' => 2000.00,
        'amount_due' => 2000.00,
        'tax_rate' => 0,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Web development',
        'quantity' => 1,
        'unit_price' => 2000,
        'sort_order' => 0,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),

        // First create attempt returns duplicate error
        '*/v2/invoicing/invoices' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'DUPLICATE_INVOICE_NUMBER']],
        ], 422),

        // Search finds existing invoice with WRONG amount
        '*/v2/invoicing/search-invoices' => Http::response([
            'items' => [[
                'id' => 'INV2-STALE-PAYPAL-ID',
                'status' => 'DRAFT',
                'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
            ]],
        ], 200),

        // Update call to sync data
        '*/v2/invoicing/invoices/INV2-STALE-PAYPAL-ID' => Http::response(['id' => 'INV2-STALE-PAYPAL-ID'], 200),

        // Send call to activate payment link
        '*/v2/invoicing/invoices/INV2-STALE-PAYPAL-ID/send' => Http::response([], 200),
    ]);

    $service = app(PayPalService::class);
    $result = $service->createInvoice($invoice);

    // Should link to the existing PayPal invoice
    expect($invoice->fresh()->paypal_invoice_id)->toBe('INV2-STALE-PAYPAL-ID');

    // Should have called update to sync data
    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), 'INV2-STALE-PAYPAL-ID');
    });
});

it('cancels mismatched SENT duplicate and creates with revised number', function () {
    $client = Client::factory()->create(['billing_email' => 'client@example.com']);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => 'INV-3000',
        'total' => 2000.00,
        'amount_due' => 2000.00,
        'tax_rate' => 0,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Web development',
        'quantity' => 1,
        'unit_price' => 2000,
        'sort_order' => 0,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),

        // Search finds existing SENT invoice with wrong amount
        '*/v2/invoicing/search-invoices' => Http::response([
            'items' => [[
                'id' => 'INV2-OLD-PAYPAL-ID',
                'status' => 'SENT',
                'amount' => ['value' => '50.00', 'currency_code' => 'USD'],
            ]],
        ], 200),

        // Cancel the old invoice
        '*/v2/invoicing/invoices/INV2-OLD-PAYPAL-ID/cancel' => Http::response([], 204),

        // Create calls: first returns duplicate, second (with revised number) succeeds
        '*/v2/invoicing/invoices' => Http::sequence()
            ->push([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'DUPLICATE_INVOICE_NUMBER']],
            ], 422)
            ->push(['href' => 'https://api-m.paypal.com/v2/invoicing/invoices/INV2-NEW-PAYPAL-ID', 'rel' => 'self', 'method' => 'GET'], 201),

        // Send the new invoice
        '*/v2/invoicing/invoices/INV2-NEW-PAYPAL-ID/send' => Http::response([], 200),
    ]);

    $service = app(PayPalService::class);
    $result = $service->createInvoice($invoice);

    // Should be linked to the NEW PayPal invoice
    expect($invoice->fresh()->paypal_invoice_id)->toBe('INV2-NEW-PAYPAL-ID');

    // Should have cancelled the old one
    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'INV2-OLD-PAYPAL-ID/cancel');
    });

    // Should have created with revised number
    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_contains($request->url(), '/v2/invoicing/invoices')) {
            return false;
        }
        $body = $request->data();

        return ($body['detail']['invoice_number'] ?? '') === 'INV-3000-R1';
    });
});

it('directly links a matching SENT duplicate without cancelling', function () {
    $client = Client::factory()->create(['billing_email' => 'client@example.com']);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => 'INV-4000',
        'total' => 2000.00,
        'amount_due' => 2000.00,
        'tax_rate' => 0,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Web development',
        'quantity' => 1,
        'unit_price' => 2000,
        'sort_order' => 0,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),

        '*/v2/invoicing/invoices' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'DUPLICATE_INVOICE_NUMBER']],
        ], 422),

        // Search finds existing SENT invoice with MATCHING amount
        '*/v2/invoicing/search-invoices' => Http::response([
            'items' => [[
                'id' => 'INV2-MATCHING-ID',
                'status' => 'SENT',
                'amount' => ['value' => '2000.00', 'currency_code' => 'USD'],
            ]],
        ], 200),
    ]);

    $service = app(PayPalService::class);
    $result = $service->createInvoice($invoice);

    expect($invoice->fresh()->paypal_invoice_id)->toBe('INV2-MATCHING-ID');

    // Should NOT have attempted to cancel
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/cancel');
    });
});
