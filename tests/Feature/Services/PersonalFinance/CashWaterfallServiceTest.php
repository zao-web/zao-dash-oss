<?php

use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\QuickBooksConnection;
use App\Models\TaxObligation;
use App\Models\User;
use App\Services\PersonalFinance\CashWaterfallService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(CashWaterfallService::class);
});

// ---------------------------------------------------------------------------
// Priority allocation order
// ---------------------------------------------------------------------------

test('waterfall allocates in correct priority order', function () {
    // Set up monthly expenses snapshot for operating reserve calculation
    $qboConnection = QuickBooksConnection::factory()->create();
    FinancialSnapshot::factory()->create([
        'qbo_connection_id' => $qboConnection->id,
        'period_type' => 'monthly',
        'total_expenses' => 20000,
    ]);

    // IRS installment: $2K/mo
    $taxDebt = Debt::factory()->taxFederal()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    TaxObligation::factory()->withInstallmentAgreement()->create([
        'debt_id' => $taxDebt->id,
        'installment_monthly' => 2000,
    ]);

    // Non-tax debts: $3K minimums
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'credit_card',
        'minimum_payment' => 1500,
        'status' => 'active',
    ]);
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'personal_loan',
        'minimum_payment' => 1500,
        'status' => 'active',
    ]);

    // Income: $10K
    $result = $this->service->allocate($this->user->id, 10000, 'manual', 'Test allocation');

    $breakdown = $result['breakdown'];

    // 1. Operating reserve: min($10K * 0.10, max($20K * 0.10, $100)) = min($1000, $2000) = $1000
    expect($breakdown['operating_reserve']['amount'])->toBe(1000.0);

    // 2. Tax reserve: min(remaining, $10K * 0.30) = min($9000, $3000) = $3000
    expect($breakdown['tax_reserve']['amount'])->toBe(3000.0);

    // 3. IRS installment: min($6000, $2000) = $2000
    expect($breakdown['irs_installment']['amount'])->toBe(2000.0);

    // 4. Debt payments: min($4000, $3000) = $3000
    expect($breakdown['debt_payments']['amount'])->toBe(3000.0);

    // 5. Owner's draw: ($4000 - $3000) = $1000 remaining, 50% = $500
    expect($breakdown['owner_draw']['amount'])->toBe(500.0);

    // 6. Investment: remainder = $500
    expect($breakdown['investment']['amount'])->toBe(500.0);

    // Total allocations should equal income
    $total = $breakdown['operating_reserve']['amount']
        + $breakdown['tax_reserve']['amount']
        + $breakdown['irs_installment']['amount']
        + $breakdown['debt_payments']['amount']
        + $breakdown['owner_draw']['amount']
        + $breakdown['investment']['amount'];

    expect($total)->toBe(10000.0);

    // Remaining should be zero
    expect($result['remaining'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Insufficient income scenario
// ---------------------------------------------------------------------------

test('waterfall handles case where income is less than obligations', function () {
    // Set up expenses for operating reserve
    $qboConnection = QuickBooksConnection::factory()->create();
    FinancialSnapshot::factory()->create([
        'qbo_connection_id' => $qboConnection->id,
        'period_type' => 'monthly',
        'total_expenses' => 20000,
    ]);

    // Large IRS installment: $5K/mo
    $taxDebt = Debt::factory()->taxFederal()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    TaxObligation::factory()->withInstallmentAgreement()->create([
        'debt_id' => $taxDebt->id,
        'installment_monthly' => 5000,
    ]);

    // Large debt minimums: $5K
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'debt_type' => 'credit_card',
        'minimum_payment' => 5000,
        'status' => 'active',
    ]);

    // Income: only $3K but obligations total much more
    $result = $this->service->allocate($this->user->id, 3000, 'manual', 'Low income test');

    $breakdown = $result['breakdown'];

    // Total allocations must equal income (no over-allocation)
    $total = $breakdown['operating_reserve']['amount']
        + $breakdown['tax_reserve']['amount']
        + $breakdown['irs_installment']['amount']
        + $breakdown['debt_payments']['amount']
        + $breakdown['owner_draw']['amount']
        + $breakdown['investment']['amount'];

    expect($total)->toBe(3000.0);

    // Operating reserve gets its share first: min($300, max($2000, $100)) = $300
    expect($breakdown['operating_reserve']['amount'])->toBe(300.0);

    // Tax reserve: min($2700, $900) = $900
    expect($breakdown['tax_reserve']['amount'])->toBe(900.0);

    // IRS installment: min($1800, $5000) = $1800
    expect($breakdown['irs_installment']['amount'])->toBe(1800.0);

    // Debt payments: min($0, $5000) = $0 (nothing left)
    expect($breakdown['debt_payments']['amount'])->toBe(0.0);

    // Owner's draw and investment should be $0
    expect($breakdown['owner_draw']['amount'])->toBe(0.0)
        ->and($breakdown['investment']['amount'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Allocation persisted to database
// ---------------------------------------------------------------------------

test('waterfall allocation is saved to database', function () {
    $result = $this->service->allocate($this->user->id, 5000, 'invoice_paid', 'Client A payment');

    expect($result['allocation'])->not->toBeNull()
        ->and($result['allocation']->id)->toBeGreaterThan(0)
        ->and((float) $result['allocation']->income_amount)->toBe(5000.0)
        ->and($result['allocation']->trigger_type)->toBe('invoice_paid')
        ->and($result['allocation']->trigger_description)->toBe('Client A payment')
        ->and($result['allocation']->is_simulation)->toBeFalse();

    $this->assertDatabaseHas('cash_waterfall_allocations', [
        'user_id' => $this->user->id,
        'income_amount' => 5000,
        'trigger_type' => 'invoice_paid',
    ]);
});

test('simulation flag is set correctly', function () {
    $result = $this->service->allocate($this->user->id, 5000, 'simulation', 'What-if scenario');

    expect($result['allocation']->is_simulation)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Zero income edge case
// ---------------------------------------------------------------------------

test('waterfall handles zero income gracefully', function () {
    $result = $this->service->allocate($this->user->id, 0, 'manual', 'No income');

    $breakdown = $result['breakdown'];

    $total = $breakdown['operating_reserve']['amount']
        + $breakdown['tax_reserve']['amount']
        + $breakdown['irs_installment']['amount']
        + $breakdown['debt_payments']['amount']
        + $breakdown['owner_draw']['amount']
        + $breakdown['investment']['amount'];

    expect($total)->toBe(0.0)
        ->and($result['remaining'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// No debts scenario
// ---------------------------------------------------------------------------

test('waterfall allocates to owner draw and investment when no debts', function () {
    $result = $this->service->allocate($this->user->id, 10000, 'manual', 'No debts');

    $breakdown = $result['breakdown'];

    // With no debts and no IRS installment, more goes to owner draw and investment
    expect($breakdown['irs_installment']['amount'])->toBe(0.0)
        ->and($breakdown['debt_payments']['amount'])->toBe(0.0)
        ->and($breakdown['owner_draw']['amount'])->toBeGreaterThan(0)
        ->and($breakdown['investment']['amount'])->toBeGreaterThan(0);

    // Owner draw and investment should split the post-reserve/tax remainder
    $afterReservesAndTax = 10000 - $breakdown['operating_reserve']['amount'] - $breakdown['tax_reserve']['amount'];
    expect($breakdown['owner_draw']['amount'] + $breakdown['investment']['amount'])->toBe(round($afterReservesAndTax, 2));
});
