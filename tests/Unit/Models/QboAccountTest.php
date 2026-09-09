<?php

use App\Models\QboAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new QboAccount)->getGuarded())->toBe([]);
});

test('casts current_balance to decimal', function () {
    $account = QboAccount::factory()->create(['current_balance' => 5000.50]);

    expect($account->current_balance)->toBeFloat();
});

test('casts active to boolean', function () {
    $account = QboAccount::factory()->create(['active' => true]);

    expect($account->active)->toBeBool()
        ->and($account->active)->toBeTrue();
});

test('casts synced_at to datetime', function () {
    $account = QboAccount::factory()->create(['synced_at' => now()]);

    expect($account->synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to quickbooks connection relationship', function () {
    $account = QboAccount::factory()->create();

    expect($account->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many transactions relationship', function () {
    $account = QboAccount::factory()->create();

    expect($account->transactions())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('active scope filters active accounts', function () {
    QboAccount::factory()->create(['active' => true, 'name' => 'Active Account']);
    QboAccount::factory()->create(['active' => false, 'name' => 'Inactive Account']);

    $activeAccounts = QboAccount::active()->get();

    expect($activeAccounts)->toHaveCount(1)
        ->and($activeAccounts->first()->name)->toBe('Active Account');
});

test('ofType scope filters by account type', function () {
    QboAccount::factory()->create(['account_type' => 'Bank']);
    QboAccount::factory()->create(['account_type' => 'Income']);

    $bankAccounts = QboAccount::ofType('Bank')->get();

    expect($bankAccounts)->toHaveCount(1)
        ->and($bankAccounts->first()->account_type)->toBe('Bank');
});

test('bank scope filters bank accounts', function () {
    QboAccount::factory()->create(['account_type' => 'Bank']);
    QboAccount::factory()->create(['account_type' => 'Income']);

    $bankAccounts = QboAccount::bank()->get();

    expect($bankAccounts)->toHaveCount(1)
        ->and($bankAccounts->first()->account_type)->toBe('Bank');
});

test('income scope filters income accounts', function () {
    QboAccount::factory()->create(['account_type' => 'Bank']);
    QboAccount::factory()->create(['account_type' => 'Income']);

    $incomeAccounts = QboAccount::income()->get();

    expect($incomeAccounts)->toHaveCount(1)
        ->and($incomeAccounts->first()->account_type)->toBe('Income');
});

test('expense scope filters expense accounts', function () {
    QboAccount::factory()->create(['account_type' => 'Expense']);
    QboAccount::factory()->create(['account_type' => 'Income']);

    $expenseAccounts = QboAccount::expense()->get();

    expect($expenseAccounts)->toHaveCount(1)
        ->and($expenseAccounts->first()->account_type)->toBe('Expense');
});

test('can be created via factory', function () {
    $account = QboAccount::factory()->create();

    expect($account)->toBeInstanceOf(QboAccount::class)
        ->and($account->exists)->toBeTrue();
});
