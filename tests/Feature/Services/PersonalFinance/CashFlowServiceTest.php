<?php

use App\Models\CashFlowForecast;
use App\Models\Debt;
use App\Models\Invoice;
use App\Models\PersonalAccount;
use App\Models\User;
use App\Services\PersonalFinance\CashFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(CashFlowService::class);
});

// ---------------------------------------------------------------------------
// Invoice deduplication
// ---------------------------------------------------------------------------

test('forecast does not double-count invoices', function () {
    $dueDate = now()->addDays(5);

    $account = PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000,
    ]);

    Invoice::factory()->sent()->create([
        'due_date' => $dueDate,
        'total' => 2500,
        'amount_due' => 2500,
    ]);

    $forecast = $this->service->generateForecast($this->user->id, 14);

    // Count how many times the invoice amount appears as an inflow across all forecast days
    $invoiceInflowCount = 0;
    foreach ($forecast['projections'] as $day) {
        foreach ($day['inflows']['items'] as $item) {
            if ($item['source'] === 'invoice') {
                $invoiceInflowCount++;
            }
        }
    }

    // The invoice should appear exactly ONCE on its due date
    expect($invoiceInflowCount)->toBe(1);
});

// ---------------------------------------------------------------------------
// Debt payment timing
// ---------------------------------------------------------------------------

test('debt payments appear on correct day of month', function () {
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 10000,
    ]);

    Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Car Loan',
        'payment_due_day' => 15,
        'minimum_payment' => 350,
        'status' => 'active',
    ]);

    // Generate a 30-day forecast
    $forecast = $this->service->generateForecast($this->user->id, 30);

    $debtPaymentDays = [];
    foreach ($forecast['projections'] as $day) {
        foreach ($day['outflows']['items'] as $item) {
            if ($item['source'] === 'debt_payment' && str_contains($item['description'], 'Car Loan')) {
                $debtPaymentDays[] = $day['date'];
            }
        }
    }

    // There should be at most 1 payment in a 30-day window
    expect(count($debtPaymentDays))->toBeLessThanOrEqual(1);

    // If there is a payment day, it should fall on the 15th of some month
    foreach ($debtPaymentDays as $dateStr) {
        $dayOfMonth = (int) \Carbon\Carbon::parse($dateStr)->day;
        expect($dayOfMonth)->toBe(15);
    }
});

// ---------------------------------------------------------------------------
// Month-end due day rollover
// ---------------------------------------------------------------------------

test('month-end due days roll over correctly', function () {
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 10000,
    ]);

    // Debt with due day 31
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Monthly Bill',
        'payment_due_day' => 31,
        'minimum_payment' => 200,
        'status' => 'active',
    ]);

    // Generate forecast spanning multiple months
    $forecast = $this->service->generateForecast($this->user->id, 90);

    $debtPaymentDays = [];
    foreach ($forecast['projections'] as $day) {
        foreach ($day['outflows']['items'] as $item) {
            if ($item['source'] === 'debt_payment' && str_contains($item['description'], 'Monthly Bill')) {
                $debtPaymentDays[] = $day['date'];
            }
        }
    }

    // Each payment should fall on the last day of its month (or the 31st if month has 31 days)
    foreach ($debtPaymentDays as $dateStr) {
        $date = \Carbon\Carbon::parse($dateStr);
        $daysInMonth = $date->daysInMonth;
        $effectiveDueDay = min(31, $daysInMonth);
        expect($date->day)->toBe($effectiveDueDay);
    }
});

// ---------------------------------------------------------------------------
// Shortfall detection
// ---------------------------------------------------------------------------

test('shortfall detection identifies negative balance days', function () {
    // Start with very low balance
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 100,
    ]);

    // Add a large debt payment that will trigger on its due day
    // This uses the debt payment outflow path which we know works from the "debt payments
    // appear on correct day" test, rather than CashFlowForecast entries
    $targetDay = now()->addDays(3)->day;
    Debt::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Huge Debt Payment',
        'payment_due_day' => $targetDay,
        'minimum_payment' => 50000,
        'status' => 'active',
    ]);

    // Also add another debt due a day later to keep the balance negative
    $targetDay2 = now()->addDays(4)->day;
    if ($targetDay2 !== $targetDay) {
        Debt::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Another Huge Debt',
            'payment_due_day' => $targetDay2,
            'minimum_payment' => 50000,
            'status' => 'active',
        ]);
    }

    $forecast = $this->service->generateForecast($this->user->id, 14);

    // With $100 personal cash and $50-100K in outflows, balance will go negative
    // Verify shortfall detection is working
    $lowestBalance = $forecast['summary']['lowest_balance'];

    // The lowest balance should be significantly negative
    expect($lowestBalance)->toBeLessThan(0);
    expect($forecast['summary']['shortfall_count'])->toBeGreaterThan(0);

    // At least one projection day should be marked as a shortfall
    $hasShortfall = false;
    foreach ($forecast['projections'] as $day) {
        if ($day['is_shortfall']) {
            $hasShortfall = true;
            expect($day['running_balance'])->toBeLessThan(0);
        }
    }
    expect($hasShortfall)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Recommendations
// ---------------------------------------------------------------------------

test('recommendations include overdue invoice alerts', function () {
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000,
    ]);

    // Create an overdue invoice
    Invoice::factory()->create([
        'status' => 'sent',
        'due_date' => now()->subDays(10),
        'total' => 3000,
        'amount_due' => 3000,
    ]);

    $forecast = $this->service->generateForecast($this->user->id, 30);

    // Should have a recommendation about overdue invoices
    $overdueRec = collect($forecast['recommendations'])->first(
        fn ($r) => $r['type'] === 'high' && str_contains($r['title'], 'Overdue')
    );

    expect($overdueRec)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Forecast structure
// ---------------------------------------------------------------------------

test('forecast returns expected data structure', function () {
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 10000,
    ]);

    $forecast = $this->service->generateForecast($this->user->id, 14);

    expect($forecast)->toHaveKeys(['start_date', 'days', 'current_cash', 'projections', 'summary', 'recommendations'])
        ->and($forecast['days'])->toBe(14)
        ->and($forecast['projections'])->toHaveCount(14)
        ->and($forecast['current_cash'])->toHaveKeys(['personal', 'business', 'total'])
        ->and($forecast['summary'])->toHaveKeys([
            'total_projected_inflows', 'total_projected_outflows',
            'ending_balance', 'lowest_balance', 'lowest_balance_date',
            'shortfall_count', 'shortfalls',
        ]);

    // First projection day should be today
    expect($forecast['projections'][0]['is_today'])->toBeTrue();
});

test('running balance is cumulative and consistent', function () {
    PersonalAccount::factory()->checking()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000,
    ]);

    $forecast = $this->service->generateForecast($this->user->id, 7);

    // Verify running balance calculation
    $projections = $forecast['projections'];
    $expectedBalance = $forecast['current_cash']['total'];

    foreach ($projections as $day) {
        $expectedBalance += $day['net_flow'];
        expect(abs($day['running_balance'] - round($expectedBalance, 2)))->toBeLessThan(0.02);
    }
});
