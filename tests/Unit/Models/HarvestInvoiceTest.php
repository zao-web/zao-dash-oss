<?php

use App\Models\HarvestInvoice;

test('has guarded attributes empty', function () {
    expect((new HarvestInvoice)->getGuarded())->toBe(['*']);
});

test('casts amount to decimal', function () {
    $invoice = HarvestInvoice::factory()->create(['amount' => 5000.50]);

    expect($invoice->amount)->toBeFloat();
});

test('casts due_amount to decimal', function () {
    $invoice = HarvestInvoice::factory()->create(['due_amount' => 2500.25]);

    expect($invoice->due_amount)->toBeFloat();
});

test('casts issue_date to date', function () {
    $invoice = HarvestInvoice::factory()->create(['issue_date' => '2025-01-01']);

    expect($invoice->issue_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts due_date to date', function () {
    $invoice = HarvestInvoice::factory()->create(['due_date' => '2025-01-31']);

    expect($invoice->due_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts sent_at to date', function () {
    $invoice = HarvestInvoice::factory()->create(['sent_at' => '2025-01-15']);

    expect($invoice->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts paid_at to date', function () {
    $invoice = HarvestInvoice::factory()->create(['paid_at' => '2025-01-20']);

    expect($invoice->paid_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to client relationship', function () {
    $invoice = HarvestInvoice::factory()->create();

    expect($invoice->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isPaid returns true when state is paid', function () {
    $invoice = HarvestInvoice::factory()->create(['state' => 'paid']);

    expect($invoice->isPaid())->toBeTrue();
});

test('isPaid returns false when state is not paid', function () {
    $invoice = HarvestInvoice::factory()->create(['state' => 'open']);

    expect($invoice->isPaid())->toBeFalse();
});

test('isOverdue returns true when open and past due date', function () {
    $invoice = HarvestInvoice::factory()->create([
        'state' => 'open',
        'due_date' => now()->subDays(5),
    ]);

    expect($invoice->isOverdue())->toBeTrue();
});

test('isOverdue returns false when paid', function () {
    $invoice = HarvestInvoice::factory()->create([
        'state' => 'paid',
        'due_date' => now()->subDays(5),
    ]);

    expect($invoice->isOverdue())->toBeFalse();
});

test('isOverdue returns false when not past due', function () {
    $invoice = HarvestInvoice::factory()->create([
        'state' => 'open',
        'due_date' => now()->addDays(5),
    ]);

    expect($invoice->isOverdue())->toBeFalse();
});

test('can be created via factory', function () {
    $invoice = HarvestInvoice::factory()->create();

    expect($invoice)->toBeInstanceOf(HarvestInvoice::class)
        ->and($invoice->exists)->toBeTrue();
});
