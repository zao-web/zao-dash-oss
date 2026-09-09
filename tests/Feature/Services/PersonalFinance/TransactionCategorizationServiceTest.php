<?php

use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mock(ClaudeCliService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(false);
    });

    $this->user = User::factory()->create();
    $this->account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(TransactionCategorizationService::class);
});

test('suggestCategory matches keyword rules', function () {
    TransactionCategory::factory()->create(['name' => 'Coffee Shops', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Starbucks #12345',
        'description' => 'Starbucks Coffee Purchase',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Coffee Shops');
});

test('suggestCategory matches fast food keywords', function () {
    TransactionCategory::factory()->create(['name' => 'Fast Food', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => "McDonald's #4567",
        'description' => 'McDonalds Purchase',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Fast Food');
});

test('suggestCategory matches subscription keywords', function () {
    TransactionCategory::factory()->create(['name' => 'Subscriptions', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Netflix.com',
        'description' => 'Netflix Monthly',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Subscriptions');
});

test('suggestCategory classifies recurring aviron charges as subscriptions', function () {
    TransactionCategory::factory()->create(['name' => 'Subscriptions', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'AVIRON',
        'description' => 'RECURRING PAYMENT AUTHORIZED ON 11/23 AVIRON ACTIVE INC. AVIRONACTIVE. GA',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Subscriptions');
});

test('suggestCategory classifies riversidefm as software', function () {
    TransactionCategory::factory()->create(['name' => 'Software/SaaS', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'RIVERSIDEFM',
        'description' => 'RECURRING PAYMENT AUTHORIZED ON 05/06 RIVERSIDEFM, INC. PALO ALTO NLD',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Software/SaaS');
});

test('suggestCategory uses description context even when a generic merchant name exists', function () {
    TransactionCategory::factory()->create(['name' => 'Software/SaaS', 'type' => 'expense']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'PAYPAL',
        'description' => 'PAYPAL INST XFER 251228 GITHUB INC ZAO WEB DESIGN',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Software/SaaS');
});

test('suggestCategory classifies expansion capital financing activity as a loan payment', function () {
    TransactionCategory::factory()->create(['name' => 'Loan Payment', 'type' => 'debt_payment']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'EXPANSION',
        'description' => 'BUSINESS TO BUSINESS ACH EXPANSION CAPITA FEE 5143134 ZAO WEB DESIGN',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Loan Payment');
});

test('suggestCategory classifies recurring business receipts as business income', function () {
    TransactionCategory::factory()->create(['name' => 'Business Income', 'type' => 'income']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'ZAO WEB DESIGN LLC',
        'description' => 'EXTENDED PLAY LL ACH PAYMEN 250115 ZAO WEB DESIGN LLC',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business Income');
});

test('suggestCategory uses previous categorization of same merchant', function () {
    $category = TransactionCategory::factory()->create(['name' => 'Custom Category', 'type' => 'expense']);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Local Coffee Shop',
        'category_id' => $category->id,
        'description' => 'Morning coffee purchase',
        'transaction_date' => now()->subDays(5),
    ]);

    $newTransaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Local Coffee Shop',
        'category_id' => null,
        'description' => 'Morning coffee purchase',
        'transaction_date' => now(),
    ]);

    $suggested = $this->service->suggestCategory($newTransaction);

    expect($suggested)->not->toBeNull()
        ->and($suggested->id)->toBe($category->id);
});

test('suggestCategory detects transfer patterns', function () {
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => 'Online Transfer to Savings',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Between Accounts');
});

test('suggestCategory detects zelle transfers', function () {
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => 'Zelle Payment To John',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Between Accounts');
});

test('suggestCategory does not treat zelle from customer-style receipts as an inter-account transfer', function () {
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'ZELLE FROM',
        'description' => 'ZELLE FROM FAITH HOWARD ON 06/26 REF # BACPCHI2H0H4',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->toBeNull();
});

test('suggestCategory does not treat paypal instant transfer purchase text as an inter-account transfer', function () {
    TransactionCategory::factory()->create(['name' => 'Software/SaaS', 'type' => 'expense']);
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'PAYPAL',
        'description' => 'PAYPAL INST XFER 251228 GITHUB INC ZAO WEB DESIGN',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Software/SaaS');
});

test('suggestCategory detects owner draw patterns', function () {
    TransactionCategory::factory()->create(['name' => 'Business <> Personal', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => 'Owner draw transfer',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business <> Personal');
});

test('suggestCategory classifies paypal money transfer activity as business and personal movement', function () {
    TransactionCategory::factory()->create(['name' => 'Business <> Personal', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'PAYPAL',
        'description' => 'MONEY TRANSFER AUTHORIZED ON 10/22 PAYPAL *paulsfishing67 Visa Direct CA',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business <> Personal');
});

test('suggestCategory classifies paypal transfer settlements for agency between accounts', function () {
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'PAYPAL',
        'description' => 'PAYPAL TRANSFER 251204 1046679957374 ZAO WEB DESIGN',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Between Accounts');
});

test('suggestCategory detects shareholder loan patterns', function () {
    TransactionCategory::factory()->create(['name' => 'Business <> Personal', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => 'Shareholder loan repayment',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business <> Personal');
});

test('suggestCategory detects reimbursement patterns', function () {
    TransactionCategory::factory()->create(['name' => 'Business <> Personal', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => 'Owner reimbursement accountable plan',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business <> Personal');
});

test('suggestCategory classifies greenlight transfers as business and personal movement', function () {
    TransactionCategory::factory()->create(['name' => 'Business <> Personal', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'GREENLIGHT',
        'description' => 'GREENLIGHT APP 250901 GREENLIGHT JUSTIN SAINTON',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Business <> Personal');
});

test('suggestCategory classifies internal agency transfers between accounts', function () {
    TransactionCategory::factory()->create(['name' => 'Between Accounts', 'type' => 'transfer']);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'ZAO WEB DESIGN',
        'description' => 'ONLINE TRANSFER FROM ZAO WEB DESIGN BUSINESS CHECKING XXXXXX8724 REF #IB0QSWPRCS ON 01/02/25',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Between Accounts');
});

test('suggestCategory returns null for unrecognized merchants', function () {
    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Totally Unknown Place XYZ123',
        'description' => 'Random Purchase',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->toBeNull();
});

test('suggestCategory returns null for empty text', function () {
    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => null,
        'description' => '',
    ]);

    $category = $this->service->suggestCategory($transaction);

    expect($category)->toBeNull();
});

test('autoCategorizeAll categorizes matching transactions', function () {
    TransactionCategory::factory()->create(['name' => 'Groceries', 'type' => 'expense']);
    TransactionCategory::factory()->create(['name' => 'Subscriptions', 'type' => 'expense']);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Walmart',
        'category_id' => null,
        'description' => 'Walmart grocery purchase',
    ]);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Netflix.com',
        'category_id' => null,
        'description' => 'Netflix monthly subscription',
    ]);

    $count = $this->service->autoCategorizeAll($this->user->id);

    expect($count)->toBe(2);

    $uncategorized = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $this->user->id))
        ->whereNull('category_id')
        ->count();

    expect($uncategorized)->toBe(0);
});

test('autoCategorizeAll skips already categorized transactions', function () {
    $existing = TransactionCategory::factory()->create(['name' => 'Already Set', 'type' => 'expense']);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Walmart',
        'category_id' => $existing->id,
    ]);

    $count = $this->service->autoCategorizeAll($this->user->id);

    expect($count)->toBe(0);
});

test('autoCategorizeAll returns zero when nothing matches', function () {
    PersonalTransaction::factory()->create([
        'personal_account_id' => $this->account->id,
        'merchant_name' => 'Totally Unknown XYZ',
        'category_id' => null,
    ]);

    $count = $this->service->autoCategorizeAll($this->user->id);

    expect($count)->toBe(0);
});
