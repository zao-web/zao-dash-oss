<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('allows full editing of a sent invoice', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->sent()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
    ]);
    $line = InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Original service',
        'quantity' => 1,
        'unit_price' => 500,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Updated subject',
        'notes' => 'Updated notes',
        'internal_notes' => null,
        'due_date' => now()->addDays(45)->toDateString(),
        'tax_rate' => 8.5,
        'project_id' => null,
        'lines' => [
            [
                'id' => $line->id,
                'type' => 'fixed',
                'description' => 'Original service',
                'quantity' => 1,
                'unit_price' => 500,
                'delete' => true,
            ],
            [
                'type' => 'fixed',
                'description' => 'New service line',
                'quantity' => 2,
                'unit_price' => 750,
            ],
        ],
    ]);

    $response->assertRedirect();

    $invoice->refresh();
    expect($invoice->subject)->toBe('Updated subject');
    expect($invoice->notes)->toBe('Updated notes');
    expect((float) $invoice->tax_rate)->toBe(8.5);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->description)->toBe('New service line');
});

it('updates issue_date, po_number, and explicit payment terms', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->sent()->create([
        'client_id' => $client->id,
        'issue_date' => '2026-04-15',
        'tax_rate' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Re-dated',
        'notes' => null,
        'internal_notes' => null,
        'issue_date' => '2026-08-01',
        'due_date' => '2026-08-31',
        'payment_terms' => 'Net 30',
        'po_number' => 'PO-4521',
        'tax_rate' => 0,
        'project_id' => null,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->issue_date->toDateString())->toBe('2026-08-01');
    expect($invoice->po_number)->toBe('PO-4521');
    // Explicit selection wins over the derived label (which measures the
    // due date against today and would produce something else here).
    expect($invoice->payment_terms)->toBe('Net 30');
});

it('leaves issue_date and po_number untouched when not submitted', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->sent()->create([
        'client_id' => $client->id,
        'issue_date' => '2026-04-15',
        'po_number' => 'PO-KEEP',
        'tax_rate' => 0,
    ]);

    $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'No date fields sent',
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
    ])->assertRedirect();

    $invoice->refresh();
    expect($invoice->issue_date->toDateString())->toBe('2026-04-15');
    expect($invoice->po_number)->toBe('PO-KEEP');
});

it('recomputes line amount and invoice totals when editing an existing line', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->sent()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
    ]);
    $line = InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 500,
        'sort_order' => 0,
    ]);

    $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => null,
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
        'lines' => [
            [
                'id' => $line->id,
                'type' => 'fixed',
                'description' => 'Service',
                'details' => 'Expanded scope',
                'quantity' => 3,
                'unit' => 'hours',
                'unit_price' => 200,
            ],
        ],
    ])->assertRedirect();

    $line->refresh();
    // The model saving hook must run so amount = quantity × unit_price.
    expect((float) $line->amount)->toBe(600.0);
    expect($line->details)->toBe('Expanded scope');
    expect($line->unit)->toBe('hours');
    expect((float) $invoice->refresh()->total)->toBe(600.0);
});

it('ignores line ids belonging to a different invoice', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->sent()->create(['client_id' => $client->id, 'tax_rate' => 0]);
    $otherInvoice = Invoice::factory()->sent()->create(['client_id' => $client->id, 'tax_rate' => 0]);
    $foreignLine = InvoiceLine::create([
        'invoice_id' => $otherInvoice->id,
        'type' => 'fixed',
        'description' => 'Belongs elsewhere',
        'quantity' => 1,
        'unit_price' => 100,
        'sort_order' => 0,
    ]);

    $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => null,
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
        'lines' => [
            [
                'id' => $foreignLine->id,
                'type' => 'fixed',
                'description' => 'Hijacked',
                'quantity' => 9,
                'unit_price' => 999,
                'delete' => true,
            ],
        ],
    ])->assertRedirect();

    // The other invoice's line is untouched — not updated, not deleted.
    $foreignLine->refresh();
    expect($foreignLine->description)->toBe('Belongs elsewhere');
});

it('prevents editing a paid invoice', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'tax_rate' => 5,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Should not change',
        'notes' => 'Should not change',
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 10,
        'project_id' => null,
        'lines' => [
            [
                'type' => 'fixed',
                'description' => 'Should not be added',
                'quantity' => 1,
                'unit_price' => 999,
            ],
        ],
    ]);

    $response->assertRedirect();

    $invoice->refresh();
    // Tax rate should remain unchanged since paid invoices are not editable
    expect((float) $invoice->tax_rate)->toBe(5.0);
    // No new lines should have been added
    expect($invoice->lines)->toHaveCount(0);
});

it('syncs to PayPal when updating a linked sent invoice', function () {
    config([
        'services.paypal.client_id' => 'test-id',
        'services.paypal.client_secret' => 'test-secret',
        'services.paypal.mode' => 'sandbox',
        'services.paypal.invoicer_email' => 'invoicer@example.com',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/v2/invoicing/invoices/*' => Http::response(['id' => 'INV2-TEST'], 200),
    ]);

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'client@example.com']);
    $invoice = Invoice::factory()->sent()->withPayPal()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 500,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Updated',
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
        'lines' => [
            [
                'type' => 'fixed',
                'description' => 'Service',
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ],
    ]);

    $response->assertRedirect();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/invoicing/invoices/')
            && $request->method() === 'PUT';
    });
});

it('saves locally even when PayPal update fails', function () {
    config([
        'services.paypal.client_id' => 'test-id',
        'services.paypal.client_secret' => 'test-secret',
        'services.paypal.mode' => 'sandbox',
        'services.paypal.invoicer_email' => 'invoicer@example.com',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/v2/invoicing/invoices/*' => Http::response(['error' => 'bad request'], 500),
    ]);

    Log::spy();

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'client@example.com']);
    $invoice = Invoice::factory()->sent()->withPayPal()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Should still save',
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 5,
        'project_id' => null,
    ]);

    $response->assertRedirect();

    $invoice->refresh();
    expect($invoice->subject)->toBe('Should still save');
    expect((float) $invoice->tax_rate)->toBe(5.0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'Failed to sync invoice update to PayPal'))
        ->once();
});

it('saves recipient_email on a PayPal-linked invoice', function () {
    config([
        'services.paypal.client_id' => 'test-id',
        'services.paypal.client_secret' => 'test-secret',
        'services.paypal.mode' => 'sandbox',
        'services.paypal.invoicer_email' => 'invoicer@example.com',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/v2/invoicing/invoices/*' => Http::response(['id' => 'INV2-TEST'], 200),
    ]);

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'billing@client.com']);
    $invoice = Invoice::factory()->sent()->withPayPal()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Test',
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
        'recipient_email' => 'override@example.com',
    ]);

    $response->assertRedirect();

    $invoice->refresh();
    expect($invoice->recipient_email)->toBe('override@example.com');
});

it('uses recipient_email override in PayPal sync', function () {
    config([
        'services.paypal.client_id' => 'test-id',
        'services.paypal.client_secret' => 'test-secret',
        'services.paypal.mode' => 'sandbox',
        'services.paypal.invoicer_email' => 'invoicer@example.com',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
        '*/v2/invoicing/invoices/*' => Http::response(['id' => 'INV2-TEST'], 200),
    ]);

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'billing@client.com']);
    $invoice = Invoice::factory()->sent()->withPayPal()->create([
        'client_id' => $client->id,
        'tax_rate' => 0,
        'recipient_email' => 'override@example.com',
    ]);
    InvoiceLine::create([
        'invoice_id' => $invoice->id,
        'type' => 'fixed',
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 500,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
        'subject' => 'Updated',
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'tax_rate' => 0,
        'project_id' => null,
        'recipient_email' => 'override@example.com',
        'lines' => [
            [
                'type' => 'fixed',
                'description' => 'Service',
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ],
    ]);

    $response->assertRedirect();

    // Verify the PayPal API was called with the override email
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v2/invoicing/invoices/')) {
            return false;
        }

        $body = json_decode($request->body(), true);

        return ($body['primary_recipients'][0]['billing_info']['email_address'] ?? null) === 'override@example.com';
    });
});
