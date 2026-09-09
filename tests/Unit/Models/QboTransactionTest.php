<?php

use App\Models\QboTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new QboTransaction)->getGuarded())->toBe([]);
});

test('casts txn_date to date', function () {
    $transaction = QboTransaction::factory()->create(['txn_date' => '2025-01-15']);

    expect($transaction->txn_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts amount to decimal', function () {
    $transaction = QboTransaction::factory()->create(['amount' => 1500.75]);

    expect($transaction->amount)->toBeFloat();
});

test('casts is_reconciled to boolean', function () {
    $transaction = QboTransaction::factory()->create(['is_reconciled' => true]);

    expect($transaction->is_reconciled)->toBeBool()
        ->and($transaction->is_reconciled)->toBeTrue();
});

test('casts synced_at to datetime', function () {
    $transaction = QboTransaction::factory()->create(['synced_at' => now()]);

    expect($transaction->synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to quickbooks connection relationship', function () {
    $transaction = QboTransaction::factory()->create();

    expect($transaction->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to account relationship', function () {
    $transaction = QboTransaction::factory()->create();

    expect($transaction->account())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $transaction = QboTransaction::factory()->create();

    expect($transaction->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $transaction = QboTransaction::factory()->create();

    expect($transaction->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('ofType scope filters by transaction type', function () {
    QboTransaction::factory()->create(['txn_type' => 'Invoice']);
    QboTransaction::factory()->create(['txn_type' => 'Expense']);

    $invoiceTransactions = QboTransaction::ofType('Invoice')->get();

    expect($invoiceTransactions)->toHaveCount(1)
        ->and($invoiceTransactions->first()->txn_type)->toBe('Invoice');
});

test('income scope filters income transactions', function () {
    QboTransaction::factory()->create(['txn_type' => 'Invoice']);
    QboTransaction::factory()->create(['txn_type' => 'Payment']);
    QboTransaction::factory()->create(['txn_type' => 'SalesReceipt']);
    QboTransaction::factory()->create(['txn_type' => 'Deposit']);
    QboTransaction::factory()->create(['txn_type' => 'Expense']);

    $incomeTransactions = QboTransaction::income()->get();

    expect($incomeTransactions)->toHaveCount(4)
        ->and($incomeTransactions->pluck('txn_type')->toArray())
        ->toMatchArray(['Invoice', 'Payment', 'SalesReceipt', 'Deposit']);
});

test('expenses scope filters expense transactions', function () {
    QboTransaction::factory()->create(['txn_type' => 'Expense']);
    QboTransaction::factory()->create(['txn_type' => 'Bill']);
    QboTransaction::factory()->create(['txn_type' => 'BillPayment']);
    QboTransaction::factory()->create(['txn_type' => 'Purchase']);
    QboTransaction::factory()->create(['txn_type' => 'Invoice']);

    $expenseTransactions = QboTransaction::expenses()->get();

    expect($expenseTransactions)->toHaveCount(4)
        ->and($expenseTransactions->pluck('txn_type')->toArray())
        ->toMatchArray(['Expense', 'Bill', 'BillPayment', 'Purchase']);
});

test('inDateRange scope filters transactions by date range', function () {
    QboTransaction::factory()->create(['txn_date' => '2025-01-05']);
    QboTransaction::factory()->create(['txn_date' => '2025-01-15']);
    QboTransaction::factory()->create(['txn_date' => '2025-01-25']);

    $rangeTransactions = QboTransaction::inDateRange('2025-01-10', '2025-01-20')->get();

    expect($rangeTransactions)->toHaveCount(1)
        ->and($rangeTransactions->first()->txn_date->format('Y-m-d'))->toBe('2025-01-15');
});

test('can be created via factory', function () {
    $transaction = QboTransaction::factory()->create();

    expect($transaction)->toBeInstanceOf(QboTransaction::class)
        ->and($transaction->exists)->toBeTrue();
});
