<?php

use App\Models\Budget;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\PersonalFinance\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(BudgetService::class);
});

test('getMonthlyBudgetStatus returns empty categories when no budgets exist', function () {
    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'])->toBeEmpty()
        ->and($result['total_budgeted'])->toBe(0.0)
        ->and($result['total_spent'])->toBe(0.0)
        ->and($result['month'])->toBe(now()->format('F Y'));
});

test('getMonthlyBudgetStatus calculates budget status correctly', function () {
    $category = TransactionCategory::factory()->expense()->create(['name' => 'Groceries']);
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 500.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 200.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'])->toHaveCount(1)
        ->and($result['categories'][0]['category_name'])->toBe('Groceries')
        ->and($result['categories'][0]['target'])->toBe(500.0)
        ->and($result['categories'][0]['spent'])->toBe(200.0)
        ->and($result['categories'][0]['remaining'])->toBe(300.0)
        ->and((int) $result['categories'][0]['percent_used'])->toBe(40)
        ->and($result['categories'][0]['status'])->toBe('on_track')
        ->and($result['total_budgeted'])->toBe(500.0);
});

test('getMonthlyBudgetStatus rolls up child category spending', function () {
    $parent = TransactionCategory::factory()->expense()->create(['name' => 'Food']);
    $child = TransactionCategory::factory()->expense()->create([
        'name' => 'Fast Food',
        'parent_id' => $parent->id,
    ]);

    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $parent->id,
        'amount' => 600.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $parent->id,
        'amount' => 100.00,
        'transaction_date' => now(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 150.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'][0]['spent'])->toBe(250.0)
        ->and($result['categories'][0]['remaining'])->toBe(350.0);
});

test('getMonthlyBudgetStatus detects warning status at 80%', function () {
    $category = TransactionCategory::factory()->expense()->create();
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 100.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 85.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'][0]['status'])->toBe('warning');
});

test('getMonthlyBudgetStatus detects over status at 100%', function () {
    $category = TransactionCategory::factory()->expense()->create();
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 100.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 120.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'][0]['status'])->toBe('over')
        ->and((int) $result['categories'][0]['percent_used'])->toBe(120);
});

test('getMonthlyBudgetStatus tracks uncategorized spending', function () {
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => null,
        'amount' => 75.50,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['uncategorized_spending'])->toBe(75.5);
});

test('getMonthlyBudgetStatus respects month parameter', function () {
    $category = TransactionCategory::factory()->expense()->create();
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);
    $lastMonth = now()->subMonth();

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 300.00,
        'effective_from' => $lastMonth->copy()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 150.00,
        'transaction_date' => $lastMonth,
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id, $lastMonth);

    expect($result['month_key'])->toBe($lastMonth->format('Y-m'))
        ->and($result['categories'][0]['spent'])->toBe(150.0);
});

test('getMonthlyBudgetStatus ignores negative amounts (inflows)', function () {
    $category = TransactionCategory::factory()->expense()->create();
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 500.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -100.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['categories'][0]['spent'])->toBe(0.0);
});

test('getSpendingBreakdown groups by top-level category', function () {
    $parent = TransactionCategory::factory()->expense()->create(['name' => 'Food']);
    $child = TransactionCategory::factory()->expense()->create([
        'name' => 'Groceries',
        'parent_id' => $parent->id,
    ]);

    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 50.00,
        'transaction_date' => now(),
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 75.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getSpendingBreakdown($this->user->id);

    expect($result)->toHaveCount(1)
        ->and($result[0]['category'])->toBe('Food')
        ->and($result[0]['total'])->toBe(125.0)
        ->and($result[0]['count'])->toBe(2);
});

test('getSpendingBreakdown returns empty for no transactions', function () {
    $result = $this->service->getSpendingBreakdown($this->user->id);

    expect($result)->toBeEmpty();
});

test('weekly budget converts to monthly correctly', function () {
    $category = TransactionCategory::factory()->expense()->create(['name' => 'Groceries']);
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    // Weekly budget: $100 for groceries
    Budget::factory()->weekly()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 100.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    // Monthly equivalent: $100 * 4.33 = $433
    // Hand-calculated: 100 * 4.33 = 433.00
    expect($result['categories'])->toHaveCount(1)
        ->and($result['categories'][0]['target'])->toBe(433.0)
        ->and($result['total_budgeted'])->toBe(433.0);
});

test('child category spending rolls up to parent correctly', function () {
    // Parent: "Food & Dining" budget $500
    $parent = TransactionCategory::factory()->expense()->create(['name' => 'Food & Dining']);
    $childGroceries = TransactionCategory::factory()->expense()->create([
        'name' => 'Groceries',
        'parent_id' => $parent->id,
    ]);
    $childRestaurants = TransactionCategory::factory()->expense()->create([
        'name' => 'Restaurants',
        'parent_id' => $parent->id,
    ]);

    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $parent->id,
        'amount' => 500.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    // Child: "Groceries" spending $200
    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $childGroceries->id,
        'amount' => 200.00,
        'transaction_date' => now(),
    ]);

    // Child: "Restaurants" spending $150
    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'category_id' => $childRestaurants->id,
        'amount' => 150.00,
        'transaction_date' => now(),
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    // Expected: Food & Dining spent = $200 + $150 = $350 (not $0)
    expect($result['categories'][0]['spent'])->toBe(350.0)
        ->and($result['categories'][0]['remaining'])->toBe(150.0)
        ->and((int) $result['categories'][0]['percent_used'])->toBe(70)
        ->and($result['categories'][0]['status'])->toBe('on_track');
});

test('getMonthlyBudgetStatus hides overlapping teller rows without deleting them', function () {
    $category = TransactionCategory::factory()->expense()->create(['name' => 'Groceries']);
    $account = PersonalAccount::factory()->creditCard()->create([
        'user_id' => $this->user->id,
        'plaid_account_id' => 'plaid_amex',
        'metadata' => ['provider' => 'plaid'],
    ]);

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 500.00,
        'effective_from' => now()->startOfMonth(),
    ]);

    $overlappingTeller = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'plaid_transaction_id' => 'teller_whole_foods',
        'import_source' => 'teller',
        'category_id' => $category->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 87.65,
        'description' => 'Whole Foods',
        'merchant_name' => 'Whole Foods',
        'notes' => 'Keep budget note',
    ]);
    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'plaid_transaction_id' => 'plaid_whole_foods',
        'import_source' => 'plaid',
        'category_id' => $category->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 87.65,
        'description' => 'Whole Foods',
        'merchant_name' => 'Whole Foods',
    ]);
    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'plaid_transaction_id' => 'teller_old_fee',
        'import_source' => 'teller',
        'category_id' => $category->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 40.00,
        'description' => 'Annual fee',
        'merchant_name' => 'Amex',
    ]);

    $status = $this->service->getMonthlyBudgetStatus($this->user->id);
    $breakdown = $this->service->getSpendingBreakdown($this->user->id);

    expect($status['categories'][0]['spent'])->toBe(127.65)
        ->and($breakdown)->toHaveCount(1)
        ->and($breakdown[0]['total'])->toBe(127.65)
        ->and($breakdown[0]['count'])->toBe(2)
        ->and(PersonalTransaction::find($overlappingTeller->id))->not->toBeNull()
        ->and($overlappingTeller->fresh()->notes)->toBe('Keep budget note');
});

test('getMonthlyBudgetStatus hides overlapping uncategorized teller rows without deleting them', function () {
    $account = PersonalAccount::factory()->creditCard()->create([
        'user_id' => $this->user->id,
        'plaid_account_id' => 'plaid_amex',
        'metadata' => ['provider' => 'plaid'],
    ]);

    $overlappingTeller = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'plaid_transaction_id' => 'teller_coffee',
        'import_source' => 'teller',
        'category_id' => null,
        'transaction_date' => now()->toDateString(),
        'amount' => 12.50,
        'description' => 'Coffee shop',
        'merchant_name' => 'Stumptown',
        'notes' => 'Keep uncategorized note',
    ]);
    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'plaid_transaction_id' => 'plaid_coffee',
        'import_source' => 'plaid',
        'category_id' => null,
        'transaction_date' => now()->toDateString(),
        'amount' => 12.50,
        'description' => 'Coffee shop',
        'merchant_name' => 'Stumptown',
    ]);

    $result = $this->service->getMonthlyBudgetStatus($this->user->id);

    expect($result['uncategorized_spending'])->toBe(12.5)
        ->and(PersonalTransaction::find($overlappingTeller->id))->not->toBeNull()
        ->and($overlappingTeller->fresh()->notes)->toBe('Keep uncategorized note');
});
