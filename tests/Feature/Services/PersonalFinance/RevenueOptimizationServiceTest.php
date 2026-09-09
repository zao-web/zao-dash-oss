<?php

use App\Models\Client;
use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\Invoice;
use App\Models\QuickBooksConnection;
use App\Models\TaxObligation;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\PersonalFinance\RevenueOptimizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(RevenueOptimizationService::class);
});

// ---------------------------------------------------------------------------
// Revenue Gap
// ---------------------------------------------------------------------------

test('revenue gap correctly identifies deficit', function () {
    // Set up obligations:
    // - Debt minimums from credit card + personal loan = $5K
    // - Tax installment from TaxObligation = $2K
    // - Operating expenses from FinancialSnapshot = $8K
    // Note: the tax debt also has a minimum_payment which goes into the debt minimums sum
    // So we set tax debt minimum_payment to 0 to keep the math clean
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'minimum_payment' => 3000,
        'status' => 'active',
        'debt_type' => 'credit_card',
    ]);

    Debt::factory()->create([
        'user_id' => $this->user->id,
        'minimum_payment' => 2000,
        'status' => 'active',
        'debt_type' => 'personal_loan',
    ]);

    // Tax installment: $2K/mo (set minimum_payment=0 so it doesn't double-count)
    $taxDebt = Debt::factory()->taxFederal()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
        'minimum_payment' => 0,
    ]);
    TaxObligation::factory()->withInstallmentAgreement()->create([
        'debt_id' => $taxDebt->id,
        'installment_monthly' => 2000,
    ]);

    // Operating expenses: $8K/mo from snapshot
    $qboConnection = QuickBooksConnection::factory()->create();
    FinancialSnapshot::factory()->create([
        'qbo_connection_id' => $qboConnection->id,
        'period_type' => 'monthly',
        'total_expenses' => 8000,
    ]);

    // Income: $10K/month average (create paid invoices over last 3 months = $30K total)
    $client = Client::factory()->create();
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 15000,
        'paid_at' => now()->subMonth(),
    ]);
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 15000,
        'paid_at' => now()->subMonths(2),
    ]);

    $result = $this->service->calculateRevenueGap($this->user->id);

    // Debt minimums: $3K + $2K + $0 = $5K
    expect($result['breakdown']['debt_minimums'])->toBe(5000.0);

    // Tax installments: $2K
    expect($result['breakdown']['tax_installments'])->toBe(2000.0);

    // Operating expenses: $8K
    expect($result['breakdown']['operating_expenses'])->toBe(8000.0);

    // Total obligations: $5K + $2K + $8K = $15K
    expect($result['monthly_obligations'])->toBe(15000.0);

    // Income: $30K / 3 months = $10K average
    expect($result['breakdown']['invoice_income'])->toBe(10000.0);

    // Gap: $15K - $10K = $5K deficit
    expect($result['gap'])->toBe(5000.0)
        ->and($result['is_deficit'])->toBeTrue();
});

test('revenue gap shows surplus when income exceeds obligations', function () {
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'minimum_payment' => 500,
        'status' => 'active',
        'debt_type' => 'credit_card',
    ]);

    // Income: high paid invoices
    $client = Client::factory()->create();
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 30000,
        'paid_at' => now()->subMonth(),
    ]);
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 30000,
        'paid_at' => now()->subMonths(2),
    ]);
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 30000,
        'paid_at' => now()->subMonths(3)->addDay(),
    ]);

    $result = $this->service->calculateRevenueGap($this->user->id);

    // Income: $90K / 3 = $30K, obligations: $500
    // Gap should be negative (surplus)
    expect($result['is_deficit'])->toBeFalse()
        ->and($result['gap'])->toBeLessThan(0);
});

// ---------------------------------------------------------------------------
// Client Profitability
// ---------------------------------------------------------------------------

test('client profitability calculates margin correctly', function () {
    $client = Client::factory()->create(['name' => 'Client A']);

    // Revenue: $10K from paid invoice
    Invoice::factory()->paid()->create([
        'client_id' => $client->id,
        'total' => 10000,
        'paid_at' => now()->subDays(5),
    ]);

    // Cost: 50 hours at $100/hr cost = $5K cost
    TimeEntry::create([
        'harvest_id' => 10001,
        'user_id' => $this->user->id,
        'client_id' => $client->id,
        'hours' => 25,
        'cost_rate' => 100,
        'is_billable' => true,
        'spent_date' => now()->subDays(10),
        'harvest_project_id' => 1,
        'harvest_task_id' => 1,
    ]);
    TimeEntry::create([
        'harvest_id' => 10002,
        'user_id' => $this->user->id,
        'client_id' => $client->id,
        'hours' => 25,
        'cost_rate' => 100,
        'is_billable' => true,
        'spent_date' => now()->subDays(5),
        'harvest_project_id' => 1,
        'harvest_task_id' => 1,
    ]);

    $result = $this->service->getClientProfitability();

    $clientA = collect($result)->firstWhere('client_name', 'Client A');

    expect($clientA)->not->toBeNull()
        ->and($clientA['revenue'])->toBe(10000.0)
        ->and($clientA['hours'])->toBe(50.0)
        ->and($clientA['cost'])->toBe(5000.0);

    // Profit: $10K - $5K = $5K
    expect($clientA['profit'])->toBe(5000.0);

    // Margin: ($5K / $10K) * 100 = 50%
    expect($clientA['margin'])->toBe(50.0);

    // Effective rate: $10K / 50 hours = $200/hr
    expect($clientA['effective_rate'])->toBe(200.0);
});

// ---------------------------------------------------------------------------
// Utilization Rate
// ---------------------------------------------------------------------------

test('utilization rate is billable hours over total hours', function () {
    // 70 billable hours
    TimeEntry::create([
        'harvest_id' => 20001,
        'user_id' => $this->user->id,
        'hours' => 70,
        'is_billable' => true,
        'spent_date' => now()->subDays(5),
        'harvest_project_id' => 1,
        'harvest_task_id' => 1,
    ]);

    // 30 non-billable hours
    TimeEntry::create([
        'harvest_id' => 20002,
        'user_id' => $this->user->id,
        'hours' => 30,
        'is_billable' => false,
        'spent_date' => now()->subDays(5),
        'harvest_project_id' => 1,
        'harvest_task_id' => 1,
    ]);

    $result = $this->service->getUtilizationRate();

    // Total: 100 hours, billable: 70
    // Utilization: (70 / 100) * 100 = 70%
    expect($result['total_hours'])->toBe(100.0)
        ->and($result['billable_hours'])->toBe(70.0)
        ->and($result['utilization_rate'])->toBe(70.0);
});

test('utilization rate returns zero when no hours logged', function () {
    $result = $this->service->getUtilizationRate();

    expect((float) $result['total_hours'])->toBe(0.0)
        ->and((float) $result['billable_hours'])->toBe(0.0)
        ->and((float) $result['utilization_rate'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Revenue gap structure
// ---------------------------------------------------------------------------

test('revenue gap returns complete breakdown', function () {
    $result = $this->service->calculateRevenueGap($this->user->id);

    expect($result)->toHaveKeys([
        'monthly_obligations', 'monthly_income', 'gap', 'is_deficit',
        'hours_needed', 'average_rate', 'breakdown',
    ])
        ->and($result['breakdown'])->toHaveKeys([
            'debt_minimums', 'tax_installments', 'operating_expenses',
            'invoice_income', 'retainer_income',
        ]);
});
