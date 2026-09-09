<?php

use App\Mail\InvoiceSentMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('logs activity when an invoice is created', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    $response = $this->actingAs($user)->post('/invoices', [
        'client_id' => $client->id,
        'project_id' => null,
        'subject' => null,
        'notes' => null,
        'internal_notes' => null,
        'due_date' => now()->addDays(30)->toDateString(),
        'lines' => [
            [
                'type' => 'fixed',
                'description' => 'Test service',
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ],
    ]);

    $response->assertRedirect();

    $invoice = Invoice::latest()->first();

    expect($invoice->activities)->toHaveCount(1);
    expect($invoice->activities->first()->type)->toBe('created');
    expect($invoice->activities->first()->user_id)->toBe($user->id);
});

it('logs activity when an invoice is sent with selected recipients', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send", [
        'recipients' => ['alice@example.com', 'bob@example.com'],
    ]);

    $response->assertRedirect();

    $activity = InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'sent')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->metadata['recipients'])->toContain('alice@example.com', 'bob@example.com');

    Mail::assertSent(InvoiceSentMail::class, 2);
});

it('logs activity when a test email is sent', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => 'owner', 'email' => 'admin@example.com']);
    $invoice = Invoice::factory()->create(['status' => Invoice::STATUS_DRAFT]);

    $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/send", [
        'is_test' => true,
    ]);

    $response->assertRedirect();

    $activity = InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'test_sent')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('admin@example.com');

    // Test send should not change invoice status
    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);

    Mail::assertSent(InvoiceSentMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
});

it('logs activity when a payment is recorded', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $invoice = Invoice::factory()->sent()->create();

    $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/payments", [
        'amount' => 100.00,
        'method' => 'ach',
        'payment_date' => now()->toDateString(),
        'reference' => null,
        'transaction_id' => null,
        'notes' => null,
    ]);

    $response->assertRedirect();

    $activity = InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'payment_recorded')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('$100.00');
    expect($activity->description)->toContain('ach');
});

it('logs activity when an invoice is cancelled', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $invoice = Invoice::factory()->sent()->create();

    $response = $this->actingAs($user)->post("/invoices/{$invoice->id}/cancel", [
        'reason' => 'Client requested cancellation',
    ]);

    $response->assertRedirect();

    $activity = InvoiceActivity::where('invoice_id', $invoice->id)
        ->where('type', 'cancelled')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->description)->toContain('Client requested cancellation');
});

it('passes contacts to the show page', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'billing@example.com']);
    $contact = ClientContact::factory()->create([
        'client_id' => $client->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'is_primary' => true,
    ]);
    $invoice = Invoice::factory()->create(['client_id' => $client->id]);

    $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Invoices/Show')
        ->has('contacts', 2) // billing email + contact
        ->where('contacts.1.email', 'jane@example.com')
    );
});
