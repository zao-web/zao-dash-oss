<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\InvoiceReminderSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to the built-in default schedule when nothing is configured', function () {
    $resolved = InvoiceReminderSchedule::resolve();

    expect($resolved['enabled'])->toBeTrue();
    expect($resolved['entries'])->toEqual(InvoiceReminderSchedule::DEFAULT_SCHEDULE);
});

it('prefers a client override over the global schedule', function () {
    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_GLOBAL,
        'enabled' => true,
        'schedule' => [['offset_days' => -3, 'enabled' => true]],
    ]);

    $client = Client::factory()->create();

    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_CLIENT,
        'client_id' => $client->id,
        'enabled' => true,
        'schedule' => [['offset_days' => 5, 'enabled' => true]],
    ]);

    $resolved = InvoiceReminderSchedule::resolve($client);

    expect($resolved['entries'])->toHaveCount(1);
    expect($resolved['entries'][0]['offset_days'])->toBe(5);
});

it('uses global schedule for clients without an override', function () {
    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_GLOBAL,
        'enabled' => true,
        'schedule' => [['offset_days' => 14, 'enabled' => true]],
    ]);

    $client = Client::factory()->create();

    $resolved = InvoiceReminderSchedule::resolve($client);

    expect($resolved['entries'][0]['offset_days'])->toBe(14);
});

it('maps offset signs to types correctly', function () {
    expect(InvoiceReminderSchedule::typeForOffset(-3))->toBe(InvoiceReminder::TYPE_BEFORE_DUE);
    expect(InvoiceReminderSchedule::typeForOffset(0))->toBe(InvoiceReminder::TYPE_ON_DUE);
    expect(InvoiceReminderSchedule::typeForOffset(7))->toBe(InvoiceReminder::TYPE_OVERDUE);
});

it('normalizes a raw schedule: clamps, dedupes, and sorts', function () {
    $raw = [
        ['offset_days' => 500, 'enabled' => true],
        ['offset_days' => -200, 'enabled' => true],
        ['offset_days' => 7, 'enabled' => false],
        ['offset_days' => 7, 'enabled' => true],
        ['offset_days' => 0, 'enabled' => true],
    ];

    $normalized = InvoiceReminderSchedule::normalize($raw);

    expect(collect($normalized)->pluck('offset_days')->all())->toBe([
        InvoiceReminderSchedule::MIN_OFFSET,
        0,
        7,
        InvoiceReminderSchedule::MAX_OFFSET,
    ]);
});

it('schedules reminders using the resolved schedule when an invoice is sent', function () {
    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_GLOBAL,
        'enabled' => true,
        'schedule' => [
            ['offset_days' => 1, 'enabled' => true],
            ['offset_days' => 10, 'enabled' => true],
            ['offset_days' => 20, 'enabled' => false],
        ],
    ]);

    $invoice = Invoice::factory()->create(['due_date' => now()->addDays(30)]);

    InvoiceReminder::scheduleForInvoice($invoice);

    expect($invoice->reminders()->where('status', InvoiceReminder::STATUS_PENDING)->count())->toBe(2);
});

it('skips scheduling when the resolved schedule master switch is off', function () {
    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_GLOBAL,
        'enabled' => false,
        'schedule' => [['offset_days' => 7, 'enabled' => true]],
    ]);

    $invoice = Invoice::factory()->create(['due_date' => now()->addDays(30)]);

    InvoiceReminder::scheduleForInvoice($invoice);

    expect($invoice->reminders()->count())->toBe(0);
});

it('skips scheduling when reminders_disabled is true on the invoice', function () {
    $invoice = Invoice::factory()->create([
        'due_date' => now()->addDays(30),
        'reminders_disabled' => true,
    ]);

    InvoiceReminder::scheduleForInvoice($invoice);

    expect($invoice->reminders()->count())->toBe(0);
});

it('rematerializes affected unpaid invoices when global schedule is saved', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $clientGlobal = Client::factory()->create();
    $clientWithOverride = Client::factory()->create();

    InvoiceReminderSchedule::create([
        'scope' => InvoiceReminderSchedule::SCOPE_CLIENT,
        'client_id' => $clientWithOverride->id,
        'enabled' => true,
        'schedule' => [['offset_days' => 1, 'enabled' => true]],
    ]);

    $globalInvoice = Invoice::factory()->sent()->create([
        'client_id' => $clientGlobal->id,
        'due_date' => now()->addDays(30),
    ]);

    $overrideInvoice = Invoice::factory()->sent()->create([
        'client_id' => $clientWithOverride->id,
        'due_date' => now()->addDays(30),
    ]);

    $this->actingAs($user)->put('/invoices/settings/reminders', [
        'enabled' => true,
        'entries' => [
            ['offset_days' => 3, 'enabled' => true],
            ['offset_days' => 9, 'enabled' => true],
        ],
    ])->assertRedirect();

    expect($globalInvoice->reminders()->where('status', 'pending')->count())->toBe(2);
    expect($overrideInvoice->reminders()->where('status', 'pending')->count())->toBe(0);
});

it('rematerializes only the target client when a client override is saved', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $invA = Invoice::factory()->sent()->create(['client_id' => $clientA->id, 'due_date' => now()->addDays(30)]);
    $invB = Invoice::factory()->sent()->create(['client_id' => $clientB->id, 'due_date' => now()->addDays(30)]);

    $this->actingAs($user)->put("/invoices/settings/reminders/clients/{$clientA->id}", [
        'enabled' => true,
        'entries' => [['offset_days' => 5, 'enabled' => true]],
    ])->assertRedirect();

    expect($invA->reminders()->where('status', 'pending')->count())->toBe(1);
    expect($invB->reminders()->where('status', 'pending')->count())->toBe(0);
});

it('cancels pending reminders when reminders are disabled for an invoice', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $invoice = Invoice::factory()->sent()->create(['due_date' => now()->addDays(30)]);
    InvoiceReminder::scheduleForInvoice($invoice);

    expect($invoice->reminders()->where('status', 'pending')->count())->toBeGreaterThan(0);

    $this->actingAs($user)->put("/invoices/{$invoice->id}/reminders-disabled", [
        'reminders_disabled' => true,
    ])->assertRedirect();

    expect($invoice->reminders()->where('status', 'pending')->count())->toBe(0);
    expect($invoice->fresh()->reminders_disabled)->toBeTrue();
});

it('re-enables and rebuilds reminders when toggled back on', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $invoice = Invoice::factory()->sent()->create([
        'due_date' => now()->addDays(30),
        'reminders_disabled' => true,
    ]);

    $this->actingAs($user)->put("/invoices/{$invoice->id}/reminders-disabled", [
        'reminders_disabled' => false,
    ])->assertRedirect();

    expect($invoice->fresh()->reminders_disabled)->toBeFalse();
    expect($invoice->reminders()->where('status', 'pending')->count())->toBeGreaterThan(0);
});

it('cancels a single pending reminder without affecting others', function () {
    $user = User::factory()->create(['role' => 'owner']);

    $invoice = Invoice::factory()->sent()->create(['due_date' => now()->addDays(30)]);
    InvoiceReminder::scheduleForInvoice($invoice);
    $target = $invoice->reminders()->where('status', 'pending')->first();
    $totalBefore = $invoice->reminders()->where('status', 'pending')->count();

    $this->actingAs($user)->delete("/invoices/{$invoice->id}/reminders/{$target->id}")
        ->assertRedirect();

    expect($invoice->reminders()->where('status', 'pending')->count())->toBe($totalBefore - 1);
    expect($target->fresh()->status)->toBe(InvoiceReminder::STATUS_CANCELLED);
});
