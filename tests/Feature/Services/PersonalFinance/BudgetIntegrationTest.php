<?php

use App\Models\Budget;
use App\Models\Debt;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\PersonalFinance\BudgetService;
use App\Services\PersonalFinance\DebtManagementService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);

    // Create basic category structure
    $this->food = TransactionCategory::create([
        'name' => 'Food & Dining', 'type' => 'expense', 'is_system' => true, 'budget_trackable' => true,
    ]);
    $this->groceries = TransactionCategory::create([
        'name' => 'Groceries', 'type' => 'expense', 'parent_id' => $this->food->id, 'budget_trackable' => true,
    ]);
    $this->debtParent = TransactionCategory::create([
        'name' => 'Debt Payments', 'type' => 'debt_payment', 'is_system' => true, 'budget_trackable' => true,
    ]);
    $this->creditCardCat = TransactionCategory::create([
        'name' => 'Credit Card Payment', 'type' => 'debt_payment', 'parent_id' => $this->debtParent->id, 'budget_trackable' => true,
    ]);
    $this->loanCat = TransactionCategory::create([
        'name' => 'Loan Payment', 'type' => 'debt_payment', 'parent_id' => $this->debtParent->id, 'budget_trackable' => true,
    ]);

    $this->account = PersonalAccount::factory()->checking()->create(['user_id' => $this->user->id]);
});

test('budget tracks spending from categorized transactions', function () {
    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'amount' => 500,
        'period_type' => 'monthly',
        'effective_from' => now()->startOfMonth(),
    ]);

    PersonalTransaction::create([
        'personal_account_id' => $this->account->id,
        'transaction_date' => now(),
        'amount' => 150.00,
        'description' => 'Kroger Groceries',
        'category_id' => $this->groceries->id,
        'import_source' => 'manual',
    ]);

    $service = app(BudgetService::class);
    $status = $service->getMonthlyBudgetStatus($this->user->id);

    $groceryBudget = collect($status['categories'])->firstWhere('category_name', 'Groceries');

    expect($groceryBudget)->not->toBeNull()
        ->and($groceryBudget['target'])->toBe(500.0)
        ->and($groceryBudget['spent'])->toBe(150.0)
        ->and($groceryBudget['remaining'])->toBe(350.0)
        ->and($groceryBudget['status'])->toBe('on_track');
});

test('debt payment creates a categorized transaction', function () {
    $debt = Debt::create([
        'user_id' => $this->user->id,
        'name' => 'Chase Credit Card',
        'debt_type' => 'credit_card',
        'creditor_name' => 'Chase',
        'original_amount' => 5000,
        'current_balance' => 3000,
        'interest_rate' => 24.99,
        'minimum_payment' => 100,
        'status' => 'active',
        'priority' => 'high',
        'personal_account_id' => $this->account->id,
        'category_id' => $this->creditCardCat->id,
    ]);

    $service = app(DebtManagementService::class);
    $payment = $service->recordPayment($debt, [
        'amount' => 600,
        'payment_date' => now()->toDateString(),
    ]);

    // Payment should have created a linked transaction
    expect($payment->personal_transaction_id)->not->toBeNull();

    $transaction = PersonalTransaction::find($payment->personal_transaction_id);
    expect($transaction)->not->toBeNull()
        ->and((float) $transaction->amount)->toBe(600.0)
        ->and($transaction->category_id)->toBe($this->creditCardCat->id)
        ->and($transaction->description)->toContain('Debt payment');
});

test('debt payment transaction appears in budget tracking', function () {
    Budget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->creditCardCat->id,
        'amount' => 600,
        'period_type' => 'monthly',
        'effective_from' => now()->startOfMonth(),
    ]);

    $debt = Debt::create([
        'user_id' => $this->user->id,
        'name' => 'Chase Credit Card',
        'debt_type' => 'credit_card',
        'creditor_name' => 'Chase',
        'original_amount' => 5000,
        'current_balance' => 3000,
        'interest_rate' => 24.99,
        'minimum_payment' => 100,
        'status' => 'active',
        'priority' => 'high',
        'personal_account_id' => $this->account->id,
        'category_id' => $this->creditCardCat->id,
    ]);

    $debtService = app(DebtManagementService::class);
    $debtService->recordPayment($debt, ['amount' => 600, 'payment_date' => now()->toDateString()]);

    $budgetService = app(BudgetService::class);
    $status = $budgetService->getMonthlyBudgetStatus($this->user->id);

    $ccBudget = collect($status['categories'])->firstWhere('category_name', 'Credit Card Payment');

    expect($ccBudget)->not->toBeNull()
        ->and($ccBudget['spent'])->toBe(600.0)
        ->and($ccBudget['status'])->toBe('over');
});

test('active debts with minimums appear as implicit budget lines', function () {
    Debt::create([
        'user_id' => $this->user->id,
        'name' => 'Upstart Loan',
        'debt_type' => 'personal_loan',
        'creditor_name' => 'Upstart',
        'original_amount' => 10000,
        'current_balance' => 8000,
        'interest_rate' => 12.0,
        'minimum_payment' => 318,
        'status' => 'active',
        'priority' => 'medium',
        'personal_account_id' => $this->account->id,
    ]);

    $service = app(BudgetService::class);
    $status = $service->getMonthlyBudgetStatus($this->user->id);

    $loanBudget = collect($status['categories'])->firstWhere('category_name', 'Upstart Loan');

    expect($loanBudget)->not->toBeNull()
        ->and($loanBudget['target'])->toBe(318.0)
        ->and($loanBudget['category_type'])->toBe('debt_payment');
});

test('auto-categorization matches expanded keyword rules', function () {
    $service = app(TransactionCategorizationService::class);

    // Tesla financing
    $tesla = PersonalTransaction::create([
        'personal_account_id' => $this->account->id,
        'transaction_date' => now(),
        'amount' => 729,
        'description' => 'Tesla Finance Payment',
        'import_source' => 'plaid',
    ]);

    TransactionCategory::create([
        'name' => 'Car Payment', 'type' => 'expense', 'budget_trackable' => true,
    ]);

    $category = $service->suggestCategory($tesla);
    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Car Payment');
});

test('manual categorization via budget page works', function () {
    $txn = PersonalTransaction::create([
        'personal_account_id' => $this->account->id,
        'transaction_date' => now(),
        'amount' => 42.50,
        'description' => 'Random Store Purchase',
        'import_source' => 'plaid',
    ]);

    $response = $this->put("/life/transactions/{$txn->id}/categorize", [
        'category_id' => $this->groceries->id,
    ]);

    $response->assertRedirect();
    expect($txn->fresh()->category_id)->toBe($this->groceries->id);
});

test('manual categorization rejects other users transactions', function () {
    $otherUser = User::factory()->create();
    $otherAccount = PersonalAccount::factory()->checking()->create(['user_id' => $otherUser->id]);

    $txn = PersonalTransaction::create([
        'personal_account_id' => $otherAccount->id,
        'transaction_date' => now(),
        'amount' => 100,
        'description' => 'Not my transaction',
        'import_source' => 'manual',
    ]);

    $response = $this->put("/life/transactions/{$txn->id}/categorize", [
        'category_id' => $this->groceries->id,
    ]);

    $response->assertForbidden();
});
