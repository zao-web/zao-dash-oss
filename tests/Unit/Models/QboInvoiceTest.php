<?php

use App\Models\QboInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new QboInvoice)->getGuarded())->toBe([]);
});

test('casts txn_date to date', function () {
    $invoice = QboInvoice::factory()->create(['txn_date' => '2025-01-01']);

    expect($invoice->txn_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts due_date to date', function () {
    $invoice = QboInvoice::factory()->create(['due_date' => '2025-01-31']);

    expect($invoice->due_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts total_amount to decimal', function () {
    $invoice = QboInvoice::factory()->create(['total_amount' => 5000.50]);

    expect($invoice->total_amount)->toBeFloat();
});

test('casts balance to decimal', function () {
    $invoice = QboInvoice::factory()->create(['balance' => 2500.25]);

    expect($invoice->balance)->toBeFloat();
});

test('casts line_items to array', function () {
    $lineItems = [
        ['description' => 'Service 1', 'amount' => 1000],
        ['description' => 'Service 2', 'amount' => 2000],
    ];
    $invoice = QboInvoice::factory()->create(['line_items' => $lineItems]);

    expect($invoice->line_items)->toBeArray()
        ->and($invoice->line_items)->toHaveCount(2);
});

test('casts synced_at to datetime', function () {
    $invoice = QboInvoice::factory()->create(['synced_at' => now()]);

    expect($invoice->synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to quickbooks connection relationship', function () {
    $invoice = QboInvoice::factory()->create();

    expect($invoice->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $invoice = QboInvoice::factory()->create();

    expect($invoice->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to harvest invoice relationship', function () {
    $invoice = QboInvoice::factory()->create();

    expect($invoice->harvestInvoice())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isPaid returns true when status is paid', function () {
    $invoice = QboInvoice::factory()->create(['status' => 'Paid', 'balance' => 0]);

    expect($invoice->isPaid())->toBeTrue();
});

test('isPaid returns true when balance is zero', function () {
    $invoice = QboInvoice::factory()->create(['status' => 'Open', 'balance' => 0]);

    expect($invoice->isPaid())->toBeTrue();
});

test('isPaid returns false when status is open with balance', function () {
    $invoice = QboInvoice::factory()->create(['status' => 'Open', 'balance' => 100.00]);

    expect($invoice->isPaid())->toBeFalse();
});

test('isOverdue returns true when open and past due date with balance', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Open',
        'due_date' => now()->subDays(5),
        'balance' => 100.00,
    ]);

    expect($invoice->isOverdue())->toBeTrue();
});

test('isOverdue returns false when paid', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Paid',
        'due_date' => now()->subDays(5),
        'balance' => 0,
    ]);

    expect($invoice->isOverdue())->toBeFalse();
});

test('isOverdue returns false when not past due', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Open',
        'due_date' => now()->addDays(5),
        'balance' => 100.00,
    ]);

    expect($invoice->isOverdue())->toBeFalse();
});

test('isOverdue returns false when no due date', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Open',
        'due_date' => null,
        'balance' => 100.00,
    ]);

    expect($invoice->isOverdue())->toBeFalse();
});

test('days_overdue accessor returns correct value for overdue invoice', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Open',
        'due_date' => now()->subDays(10),
        'balance' => 100.00,
    ]);

    expect($invoice->days_overdue)->toBe(10);
});

test('days_overdue accessor returns null for non-overdue invoice', function () {
    $invoice = QboInvoice::factory()->create([
        'status' => 'Open',
        'due_date' => now()->addDays(5),
        'balance' => 100.00,
    ]);

    expect($invoice->days_overdue)->toBeNull();
});

test('open scope filters open invoices with balance', function () {
    QboInvoice::factory()->create(['status' => 'Open', 'balance' => 100.00]);
    QboInvoice::factory()->create(['status' => 'Paid', 'balance' => 0]);
    QboInvoice::factory()->create(['status' => 'Open', 'balance' => 0]);

    $openInvoices = QboInvoice::open()->get();

    expect($openInvoices)->toHaveCount(1)
        ->and($openInvoices->first()->status)->toBe('Open')
        ->and($openInvoices->first()->balance)->toBeGreaterThan(0);
});

test('paid scope filters paid invoices', function () {
    QboInvoice::factory()->create(['status' => 'Paid']);
    QboInvoice::factory()->create(['status' => 'Open']);

    $paidInvoices = QboInvoice::paid()->get();

    expect($paidInvoices)->toHaveCount(1)
        ->and($paidInvoices->first()->status)->toBe('Paid');
});

test('overdue scope filters overdue invoices', function () {
    QboInvoice::factory()->create([
        'status' => 'Open',
        'balance' => 100.00,
        'due_date' => now()->subDays(5),
    ]);
    QboInvoice::factory()->create([
        'status' => 'Open',
        'balance' => 100.00,
        'due_date' => now()->addDays(5),
    ]);
    QboInvoice::factory()->create([
        'status' => 'Paid',
        'balance' => 0,
        'due_date' => now()->subDays(5),
    ]);

    $overdueInvoices = QboInvoice::overdue()->get();

    expect($overdueInvoices)->toHaveCount(1);
});

test('can be created via factory', function () {
    $invoice = QboInvoice::factory()->create();

    expect($invoice)->toBeInstanceOf(QboInvoice::class)
        ->and($invoice->exists)->toBeTrue();
});
