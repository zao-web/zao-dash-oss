<?php

use App\Models\FinancialSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new FinancialSnapshot)->getGuarded())->toBe([]);
});

test('casts period_start to date', function () {
    $snapshot = FinancialSnapshot::factory()->create(['period_start' => '2025-01-01']);

    expect($snapshot->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $snapshot = FinancialSnapshot::factory()->create(['period_end' => '2025-01-31']);

    expect($snapshot->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts total_income to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['total_income' => 10000.50]);

    expect($snapshot->total_income)->toBeFloat();
});

test('casts total_expenses to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['total_expenses' => 5000.25]);

    expect($snapshot->total_expenses)->toBeFloat();
});

test('casts net_profit to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['net_profit' => 5000.25]);

    expect($snapshot->net_profit)->toBeFloat();
});

test('casts accounts_receivable to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['accounts_receivable' => 3000.00]);

    expect($snapshot->accounts_receivable)->toBeFloat();
});

test('casts accounts_payable to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['accounts_payable' => 2000.00]);

    expect($snapshot->accounts_payable)->toBeFloat();
});

test('casts cash_on_hand to decimal', function () {
    $snapshot = FinancialSnapshot::factory()->create(['cash_on_hand' => 15000.00]);

    expect($snapshot->cash_on_hand)->toBeFloat();
});

test('casts top_expense_categories to array', function () {
    $categories = [
        ['name' => 'Software', 'amount' => 1000],
        ['name' => 'Labor', 'amount' => 3000],
    ];
    $snapshot = FinancialSnapshot::factory()->create(['top_expense_categories' => $categories]);

    expect($snapshot->top_expense_categories)->toBeArray()
        ->and($snapshot->top_expense_categories)->toHaveCount(2);
});

test('casts top_income_sources to array', function () {
    $sources = [
        ['name' => 'Client A', 'amount' => 5000],
        ['name' => 'Client B', 'amount' => 3000],
    ];
    $snapshot = FinancialSnapshot::factory()->create(['top_income_sources' => $sources]);

    expect($snapshot->top_income_sources)->toBeArray()
        ->and($snapshot->top_income_sources)->toHaveCount(2);
});

test('casts insights to array', function () {
    $insights = ['Profit margin increased', 'Expenses decreased'];
    $snapshot = FinancialSnapshot::factory()->create(['insights' => $insights]);

    expect($snapshot->insights)->toBeArray()
        ->and($snapshot->insights)->toHaveCount(2);
});

test('belongs to quickbooks connection relationship', function () {
    $snapshot = FinancialSnapshot::factory()->create();

    expect($snapshot->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('profit_margin accessor calculates correct percentage', function () {
    $snapshot = FinancialSnapshot::factory()->create([
        'total_income' => 10000.00,
        'net_profit' => 2500.00,
    ]);

    expect($snapshot->profit_margin)->toBe(25.0);
});

test('profit_margin accessor returns null when income is zero', function () {
    $snapshot = FinancialSnapshot::factory()->create([
        'total_income' => 0,
        'net_profit' => 0,
    ]);

    expect($snapshot->profit_margin)->toBeNull();
});

test('expense_ratio accessor calculates correct percentage', function () {
    $snapshot = FinancialSnapshot::factory()->create([
        'total_income' => 10000.00,
        'total_expenses' => 7500.00,
    ]);

    expect($snapshot->expense_ratio)->toBe(75.0);
});

test('expense_ratio accessor returns null when income is zero', function () {
    $snapshot = FinancialSnapshot::factory()->create([
        'total_income' => 0,
        'total_expenses' => 0,
    ]);

    expect($snapshot->expense_ratio)->toBeNull();
});

test('daily scope filters daily snapshots', function () {
    FinancialSnapshot::factory()->create(['period_type' => 'daily']);
    FinancialSnapshot::factory()->create(['period_type' => 'monthly']);

    $dailySnapshots = FinancialSnapshot::daily()->get();

    expect($dailySnapshots)->toHaveCount(1)
        ->and($dailySnapshots->first()->period_type)->toBe('daily');
});

test('weekly scope filters weekly snapshots', function () {
    FinancialSnapshot::factory()->create(['period_type' => 'weekly']);
    FinancialSnapshot::factory()->create(['period_type' => 'monthly']);

    $weeklySnapshots = FinancialSnapshot::weekly()->get();

    expect($weeklySnapshots)->toHaveCount(1)
        ->and($weeklySnapshots->first()->period_type)->toBe('weekly');
});

test('monthly scope filters monthly snapshots', function () {
    FinancialSnapshot::factory()->create(['period_type' => 'monthly']);
    FinancialSnapshot::factory()->create(['period_type' => 'daily']);

    $monthlySnapshots = FinancialSnapshot::monthly()->get();

    expect($monthlySnapshots)->toHaveCount(1)
        ->and($monthlySnapshots->first()->period_type)->toBe('monthly');
});

test('forPeriod scope filters snapshots by date range', function () {
    FinancialSnapshot::factory()->create([
        'period_start' => '2025-01-01',
        'period_end' => '2025-01-31',
    ]);
    FinancialSnapshot::factory()->create([
        'period_start' => '2025-02-01',
        'period_end' => '2025-02-28',
    ]);
    FinancialSnapshot::factory()->create([
        'period_start' => '2025-03-01',
        'period_end' => '2025-03-31',
    ]);

    $periodSnapshots = FinancialSnapshot::forPeriod('2025-01-01', '2025-02-28')->get();

    expect($periodSnapshots)->toHaveCount(2);
});

test('latest static method returns most recent snapshot for given period type', function () {
    FinancialSnapshot::factory()->create([
        'period_type' => 'monthly',
        'period_end' => '2025-01-31',
    ]);
    FinancialSnapshot::factory()->create([
        'period_type' => 'monthly',
        'period_end' => '2025-02-28',
    ]);
    FinancialSnapshot::factory()->create([
        'period_type' => 'daily',
        'period_end' => '2025-03-01',
    ]);

    $latestMonthly = FinancialSnapshot::latest('monthly');

    expect($latestMonthly)->not->toBeNull()
        ->and($latestMonthly->period_end->format('Y-m-d'))->toBe('2025-02-28');
});

test('latest static method returns null when no snapshots exist', function () {
    $latest = FinancialSnapshot::latest('monthly');

    expect($latest)->toBeNull();
});

test('can be created via factory', function () {
    $snapshot = FinancialSnapshot::factory()->create();

    expect($snapshot)->toBeInstanceOf(FinancialSnapshot::class)
        ->and($snapshot->exists)->toBeTrue();
});
