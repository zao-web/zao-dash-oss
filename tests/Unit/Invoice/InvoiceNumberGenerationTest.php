<?php

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates sequential invoice numbers', function () {
    $client = Client::factory()->create();

    $invoice1 = Invoice::createWithUniqueNumber([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    $invoice2 = Invoice::createWithUniqueNumber([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    expect((int) $invoice2->number)->toBe((int) $invoice1->number + 1);
});

it('retries on unique constraint violation', function () {
    $client = Client::factory()->create();

    // Create first invoice to establish a number
    $invoice1 = Invoice::createWithUniqueNumber([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    // Manually insert a conflicting number to simulate race condition
    $nextNumber = Invoice::generateNumber();
    Invoice::create([
        'client_id' => $client->id,
        'number' => $nextNumber,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    // Now createWithUniqueNumber should retry and get the next available number
    $invoice3 = Invoice::createWithUniqueNumber([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    // Should have gotten a higher number after retry
    expect((int) $invoice3->number)->toBeGreaterThan((int) $nextNumber);
});

it('starts from minimum number 1979 when no invoices exist', function () {
    $client = Client::factory()->create();

    $invoice = Invoice::createWithUniqueNumber([
        'client_id' => $client->id,
        'status' => Invoice::STATUS_DRAFT,
        'subtotal' => 0,
        'tax_rate' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'amount_due' => 0,
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'currency' => 'USD',
    ]);

    expect((int) $invoice->number)->toBe(1979);
});
