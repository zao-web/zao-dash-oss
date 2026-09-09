<?php

use App\Models\HarvestInvoice;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('handles time entry created event', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.created',
        'time_entry' => [
            'id' => 12345,
            'hours' => 5.5,
            'notes' => 'Working on feature',
            'is_running' => false,
            'is_billed' => false,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Time entry processed']);
});

test('handles time entry updated event', function () {
    $entry = TimeEntry::factory()->create([
        'harvest_id' => 12345,
        'hours' => 3.0,
        'notes' => 'Initial notes',
        'is_running' => false,
        'is_billed' => false,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.updated',
        'time_entry' => [
            'id' => 12345,
            'hours' => 5.5,
            'notes' => 'Updated notes',
            'is_running' => false,
            'is_billed' => true,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Time entry processed']);

    $entry->refresh();
    expect($entry->hours)->toBe('5.50');
    expect($entry->notes)->toBe('Updated notes');
    expect($entry->is_billed)->toBeTrue();
});

test('handles time entry with partial update', function () {
    $entry = TimeEntry::factory()->create([
        'harvest_id' => 12345,
        'hours' => 3.0,
        'notes' => 'Original notes',
        'is_running' => false,
        'is_billed' => false,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.updated',
        'time_entry' => [
            'id' => 12345,
            'hours' => 4.0,
        ],
    ]);

    $response->assertStatus(200);

    $entry->refresh();
    expect($entry->hours)->toBe('4.00');
    expect($entry->notes)->toBe('Original notes'); // unchanged
});

test('returns error for time entry without id', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.created',
        'time_entry' => [
            'hours' => 5.5,
            'notes' => 'Working on feature',
        ],
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Missing time entry ID']);
});

test('handles time entry deleted event', function () {
    $entry = TimeEntry::factory()->create([
        'harvest_id' => 12345,
    ]);

    expect(TimeEntry::where('harvest_id', 12345)->exists())->toBeTrue();

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.deleted',
        'time_entry' => [
            'id' => 12345,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Time entry deleted']);

    expect(TimeEntry::where('harvest_id', 12345)->exists())->toBeFalse();
});

test('handles time entry deleted for non-existent entry', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.deleted',
        'time_entry' => [
            'id' => 99999,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Time entry deleted']);
});

test('handles invoice created event', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.created',
        'invoice' => [
            'id' => 54321,
            'state' => 'draft',
            'amount' => 5000.00,
            'due_amount' => 5000.00,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Invoice processed']);
});

test('handles invoice updated event', function () {
    $invoice = HarvestInvoice::factory()->create([
        'harvest_id' => 54321,
        'state' => 'draft',
        'amount' => 5000.00,
        'due_amount' => 5000.00,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.updated',
        'invoice' => [
            'id' => 54321,
            'state' => 'sent',
            'amount' => 5000.00,
            'due_amount' => 5000.00,
            'sent_at' => '2024-01-15',
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Invoice processed']);

    $invoice->refresh();
    expect($invoice->state)->toBe('sent');
    expect($invoice->sent_at)->not->toBeNull();
});

test('handles invoice paid event', function () {
    $invoice = HarvestInvoice::factory()->create([
        'harvest_id' => 54321,
        'state' => 'sent',
        'amount' => 5000.00,
        'due_amount' => 5000.00,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.updated',
        'invoice' => [
            'id' => 54321,
            'state' => 'paid',
            'amount' => 5000.00,
            'due_amount' => 0.00,
            'paid_at' => '2024-01-20',
        ],
    ]);

    $response->assertStatus(200);

    $invoice->refresh();
    expect($invoice->state)->toBe('paid');
    expect($invoice->due_amount)->toBe('0.00');
    expect($invoice->paid_at)->not->toBeNull();
});

test('returns error for invoice without id', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.created',
        'invoice' => [
            'state' => 'draft',
            'amount' => 5000.00,
        ],
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Missing invoice ID']);
});

test('handles invoice deleted event', function () {
    $invoice = HarvestInvoice::factory()->create([
        'harvest_id' => 54321,
    ]);

    expect(HarvestInvoice::where('harvest_id', 54321)->exists())->toBeTrue();

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.deleted',
        'invoice' => [
            'id' => 54321,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Invoice deleted']);

    expect(HarvestInvoice::where('harvest_id', 54321)->exists())->toBeFalse();
});

test('handles invoice deleted for non-existent invoice', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.deleted',
        'invoice' => [
            'id' => 99999,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Invoice deleted']);
});

test('handles invoice with partial update', function () {
    $invoice = HarvestInvoice::factory()->create([
        'harvest_id' => 54321,
        'state' => 'sent',
        'amount' => 5000.00,
        'due_amount' => 5000.00,
        'sent_at' => now()->subDays(5),
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'invoice.updated',
        'invoice' => [
            'id' => 54321,
            'due_amount' => 2500.00,
        ],
    ]);

    $response->assertStatus(200);

    $invoice->refresh();
    expect($invoice->state)->toBe('sent'); // unchanged
    expect($invoice->amount)->toBe('5000.00'); // unchanged
    expect($invoice->due_amount)->toBe('2500.00'); // updated
});

test('handles unhandled event types', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'project.created',
        'project' => [
            'id' => 123,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Event not handled']);
});

test('handles missing event field', function () {
    $response = $this->postJson('/webhooks/harvest', [
        'time_entry' => [
            'id' => 12345,
            'hours' => 5.5,
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Event not handled']);
});

test('handles time entry with running status', function () {
    $entry = TimeEntry::factory()->create([
        'harvest_id' => 12345,
        'is_running' => false,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.updated',
        'time_entry' => [
            'id' => 12345,
            'is_running' => true,
        ],
    ]);

    $response->assertStatus(200);

    $entry->refresh();
    expect($entry->is_running)->toBeTrue();
});

test('handles time entry with billed status change', function () {
    $entry = TimeEntry::factory()->create([
        'harvest_id' => 12345,
        'is_billed' => false,
    ]);

    $response = $this->postJson('/webhooks/harvest', [
        'event' => 'time_entry.updated',
        'time_entry' => [
            'id' => 12345,
            'is_billed' => true,
        ],
    ]);

    $response->assertStatus(200);

    $entry->refresh();
    expect($entry->is_billed)->toBeTrue();
});
