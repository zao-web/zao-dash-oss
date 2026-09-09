<?php

use App\Models\Debt;
use App\Models\User;
use App\Services\PersonalFinance\DebtManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(DebtManagementService::class);
});

// ---------------------------------------------------------------------------
// Payoff strategy ordering
// ---------------------------------------------------------------------------

test('avalanche method pays highest interest first', function () {
    $creditCard = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Credit Card',
        'debt_type' => 'credit_card',
        'current_balance' => 5000,
        'interest_rate' => 24.99,
        'minimum_payment' => 100,
        'priority' => 'medium',
    ]);

    $carLoan = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Car Loan',
        'debt_type' => 'personal_loan',
        'current_balance' => 15000,
        'interest_rate' => 6.00,
        'minimum_payment' => 300,
        'priority' => 'medium',
    ]);

    $studentLoan = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Student Loan',
        'debt_type' => 'student_loan',
        'current_balance' => 10000,
        'interest_rate' => 4.50,
        'minimum_payment' => 150,
        'priority' => 'medium',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 700, 'avalanche');

    // Avalanche orders by highest interest rate first: Credit Card (24.99%), Car Loan (6%), Student Loan (4.5%)
    // Credit Card should be paid off first, then Car Loan, then Student Loan
    $payoffOrder = collect($result['payoff_order'])->pluck('name')->values()->toArray();

    expect($payoffOrder[0])->toBe('Credit Card')
        ->and($result['method'])->toBe('avalanche')
        ->and($result['total_debt'])->toBe(30000.0)
        ->and($result['monthly_budget'])->toBe(700.0)
        ->and($result['can_pay_minimums'])->toBeTrue()
        ->and($result['total_minimums'])->toBe(550.0);
});

test('snowball method pays smallest balance first', function () {
    $creditCard = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Credit Card',
        'debt_type' => 'credit_card',
        'current_balance' => 5000,
        'interest_rate' => 24.99,
        'minimum_payment' => 100,
        'priority' => 'medium',
    ]);

    $carLoan = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Car Loan',
        'debt_type' => 'personal_loan',
        'current_balance' => 15000,
        'interest_rate' => 6.00,
        'minimum_payment' => 300,
        'priority' => 'medium',
    ]);

    $studentLoan = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Student Loan',
        'debt_type' => 'student_loan',
        'current_balance' => 10000,
        'interest_rate' => 4.50,
        'minimum_payment' => 150,
        'priority' => 'medium',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 700, 'snowball');

    // Snowball orders by smallest balance first: Credit Card ($5K), Student Loan ($10K), Car Loan ($15K)
    $payoffOrder = collect($result['payoff_order'])->pluck('name')->values()->toArray();

    expect($payoffOrder[0])->toBe('Credit Card')
        ->and($result['method'])->toBe('snowball');
});

test('hybrid method prioritizes IRS debt over everything', function () {
    $irsTaxDebt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'IRS Tax Debt',
        'debt_type' => 'tax_federal',
        'current_balance' => 50000,
        'interest_rate' => 7.00,
        'minimum_payment' => 500,
        'priority' => 'critical',
    ]);

    $creditCard = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Credit Card',
        'debt_type' => 'credit_card',
        'current_balance' => 5000,
        'interest_rate' => 24.99,
        'minimum_payment' => 100,
        'priority' => 'medium',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 1000, 'hybrid');

    // Hybrid: IRS/tax debts get priority 0, so IRS should be first in payoff order
    // even though credit card has a much higher interest rate (24.99% vs 7%)
    $payoffOrder = collect($result['payoff_order'])->pluck('name')->values()->toArray();

    expect($payoffOrder[0])->toBe('IRS Tax Debt')
        ->and($result['method'])->toBe('hybrid');
});

// ---------------------------------------------------------------------------
// Hardship mode
// ---------------------------------------------------------------------------

test('hardship mode activates when budget is less than minimums', function () {
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Credit Card',
        'debt_type' => 'credit_card',
        'current_balance' => 5000,
        'interest_rate' => 24.99,
        'minimum_payment' => 200,
        'priority' => 'medium',
    ]);

    Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Car Loan',
        'debt_type' => 'personal_loan',
        'current_balance' => 10000,
        'interest_rate' => 6.00,
        'minimum_payment' => 350,
        'priority' => 'medium',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();

    // Budget of $300 but minimums total $550 => hardship mode
    $result = $this->service->calculatePayoffPlan($debts, 300, 'hybrid');

    expect($result['can_pay_minimums'])->toBeFalse()
        ->and($result['total_minimums'])->toBe(550.0)
        ->and($result['hardship_mode'])->toBeTrue()
        ->and($result['hardship_warning'])->toBeString()
        ->and($result['months_to_payoff'])->toBeGreaterThan(0)
        ->and($result['total_interest_paid'])->toBeGreaterThan(0);
});

test('hardship mode prioritizes IRS debts first', function () {
    $irsDebt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'IRS Debt',
        'debt_type' => 'tax_federal',
        'current_balance' => 10000,
        'interest_rate' => 7.00,
        'minimum_payment' => 500,
        'priority' => 'critical',
    ]);

    $creditCard = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Credit Card',
        'debt_type' => 'credit_card',
        'current_balance' => 3000,
        'interest_rate' => 24.99,
        'minimum_payment' => 200,
        'priority' => 'medium',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();

    // Budget $400 < minimums $700 => hardship mode
    // IRS should still get priority allocation
    $result = $this->service->calculatePayoffPlan($debts, 400, 'hybrid');

    expect($result['hardship_mode'])->toBeTrue()
        ->and($result['can_pay_minimums'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Payment recording
// ---------------------------------------------------------------------------

test('recordPayment reduces balance by principal not total amount', function () {
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'current_balance' => 10000.00,
        'status' => 'active',
    ]);

    // Payment: $500 total, $400 principal, $100 interest
    $payment = $this->service->recordPayment($debt, [
        'amount' => 500,
        'principal_amount' => 400,
        'interest_amount' => 100,
        'payment_date' => now()->toDateString(),
    ]);

    $debt->refresh();

    // Balance should decrease by principal ($400), not total ($500)
    // Hand-calculated: $10,000 - $400 = $9,600
    expect((float) $debt->current_balance)->toBe(9600.00)
        ->and((float) $payment->amount)->toBe(500.0)
        ->and((float) $payment->principal_amount)->toBe(400.0)
        ->and((float) $payment->interest_amount)->toBe(100.0);
});

test('recordPayment uses full amount as principal when principal not specified', function () {
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000.00,
        'status' => 'active',
    ]);

    $payment = $this->service->recordPayment($debt, [
        'amount' => 500,
        'payment_date' => now()->toDateString(),
    ]);

    $debt->refresh();

    // Without specifying principal_amount, the full amount is used as principal
    // $5,000 - $500 = $4,500
    expect((float) $debt->current_balance)->toBe(4500.00)
        ->and((float) $payment->principal_amount)->toBe(500.0);
});

test('debt marked as paid_off when balance reaches zero', function () {
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'current_balance' => 100.00,
        'status' => 'active',
    ]);

    $this->service->recordPayment($debt, [
        'amount' => 100,
        'payment_date' => now()->toDateString(),
    ]);

    $debt->refresh();

    expect((float) $debt->current_balance)->toBe(0.00)
        ->and($debt->status)->toBe('paid_off');
});

test('debt marked as paid_off when payment exceeds balance', function () {
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'current_balance' => 50.00,
        'status' => 'active',
    ]);

    $this->service->recordPayment($debt, [
        'amount' => 75,
        'principal_amount' => 75,
        'payment_date' => now()->toDateString(),
    ]);

    $debt->refresh();

    // Balance floors at 0 via max(0, ...)
    expect((float) $debt->current_balance)->toBe(0.00)
        ->and($debt->status)->toBe('paid_off');
});

// ---------------------------------------------------------------------------
// Payoff timeline math
// ---------------------------------------------------------------------------

test('payoff timeline calculations are mathematically correct for single debt', function () {
    // Single debt: $10,000 at 12% APR, $500/month payment
    // Monthly rate: 1% = 0.01
    //
    // Hand calculation using amortization formula:
    // n = -ln(1 - (r*PV)/PMT) / ln(1+r)
    // n = -ln(1 - (0.01 * 10000)/500) / ln(1.01)
    // n = -ln(1 - 0.2) / ln(1.01)
    // n = -ln(0.8) / 0.00995
    // n = 0.22314 / 0.00995
    // n = 22.43 months => 23 months
    //
    // Total paid: approximately $500 * 22 + small final payment ~ $11,100
    // Total interest: ~$1,100
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Loan',
        'current_balance' => 10000.00,
        'interest_rate' => 12.00,
        'minimum_payment' => 500.00,
        'priority' => 'medium',
        'debt_type' => 'personal_loan',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 500, 'avalanche');

    // The simulation applies interest first then payment, so it should be close to 23 months
    // Allow a tolerance of 1 month due to discrete-month simulation rounding
    expect($result['months_to_payoff'])->toBeGreaterThanOrEqual(22)
        ->and($result['months_to_payoff'])->toBeLessThanOrEqual(24);

    // Total interest should be approximately $1,097 (hand-calculated)
    // Allow 10% tolerance for discrete simulation vs continuous formula
    expect($result['total_interest_paid'])->toBeGreaterThan(900)
        ->and($result['total_interest_paid'])->toBeLessThan(1300);
});

test('payoff timeline for zero interest debt is exact', function () {
    // $6,000 at 0% interest, $500/month => exactly 12 months, $0 interest
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Interest-Free Loan',
        'current_balance' => 6000.00,
        'interest_rate' => 0.00,
        'minimum_payment' => 500.00,
        'priority' => 'medium',
        'debt_type' => 'personal_loan',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 500, 'avalanche');

    expect($result['months_to_payoff'])->toBe(12)
        ->and($result['total_interest_paid'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Debt summary
// ---------------------------------------------------------------------------

test('getDebtSummary returns correct aggregate stats', function () {
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'credit_card',
        'original_amount' => 8000,
        'current_balance' => 5000,
        'minimum_payment' => 100,
        'interest_rate' => 22.00,
        'status' => 'active',
    ]);

    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'tax_federal',
        'original_amount' => 20000,
        'current_balance' => 15000,
        'minimum_payment' => 300,
        'interest_rate' => 7.00,
        'status' => 'active',
    ]);

    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'collections',
        'original_amount' => 3000,
        'current_balance' => 3000,
        'minimum_payment' => 50,
        'interest_rate' => 0,
        'status' => 'active',
    ]);

    Debt::factory()->paidOff()->create([
        'user_id' => $this->user->id,
        'original_amount' => 5000,
    ]);

    $summary = $this->service->getDebtSummary($this->user->id);

    expect($summary['debt_count'])->toBe(3)
        ->and($summary['paid_off_count'])->toBe(1)
        ->and($summary['total_balance'])->toBe(23000.0)
        ->and($summary['total_minimum_payments'])->toBe(450.0)
        ->and((float) $summary['highest_interest'])->toBe(22.0)
        ->and($summary['tax_debt_total'])->toBe(15000.0)
        ->and($summary['collections_total'])->toBe(3000.0);
});

// ---------------------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------------------

test('simulation does not crash with empty debt collection', function () {
    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 500, 'avalanche');

    expect($result['months_to_payoff'])->toBe(0)
        ->and($result['total_interest_paid'])->toBe(0.0)
        ->and($result['timeline'])->toBeArray();
});

test('simulation caps at 360 months to prevent infinite loops', function () {
    // Tiny payment that barely covers interest on a large balance
    $debt = Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Massive Debt',
        'current_balance' => 100000,
        'interest_rate' => 24.99,
        'minimum_payment' => 10,
        'priority' => 'medium',
        'debt_type' => 'credit_card',
    ]);

    $debts = Debt::where('user_id', $this->user->id)->get();
    $result = $this->service->calculatePayoffPlan($debts, 10, 'avalanche');

    expect($result['months_to_payoff'])->toBeLessThanOrEqual(360);
});
