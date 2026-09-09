<?php

use App\Models\BookkeepingAdjustment;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses AI to auto-apply high-confidence bookkeeping reclasses', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);
    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-02-02',
        'amount' => 249,
        'merchant_name' => 'ACME CLOUD',
        'description' => 'Monthly platform subscription',
        'category_id' => null,
    ]);

    $this->mock(ClaudeCliService::class, function ($mock) use ($transaction, $expenseCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->once()
            ->andReturn([
                'classifications' => [
                    [
                        'transaction_id' => $transaction->id,
                        'category_id' => $expenseCategory->id,
                        'confidence' => 0.93,
                        'rationale' => 'This looks like a recurring software subscription expense.',
                    ],
                ],
            ]);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(1)
        ->and($result['suggested_count'])->toBe(0)
        ->and($result['unresolved_count'])->toBe(0)
        ->and($transaction->fresh()->category_id)->toBe($expenseCategory->id);

    $adjustment = BookkeepingAdjustment::query()->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment?->status)->toBe(BookkeepingAdjustment::STATUS_APPLIED)
        ->and($adjustment?->source)->toBe(BookkeepingAdjustment::SOURCE_AI)
        ->and((float) $adjustment?->confidence)->toBe(0.93);
});

it('records lower-confidence AI bookkeeping suggestions without auto-applying them', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);
    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-02-02',
        'amount' => 199,
        'merchant_name' => 'Unknown vendor',
        'description' => 'Subscription purchase',
        'category_id' => null,
    ]);

    $this->mock(ClaudeCliService::class, function ($mock) use ($transaction, $expenseCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->once()
            ->andReturn([
                'classifications' => [
                    [
                        'transaction_id' => $transaction->id,
                        'category_id' => $expenseCategory->id,
                        'confidence' => 0.72,
                        'rationale' => 'Looks like a software subscription, but the merchant is weakly identified.',
                    ],
                ],
            ]);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(0)
        ->and($result['suggested_count'])->toBe(1)
        ->and($transaction->fresh()->category_id)->toBeNull();

    $adjustment = BookkeepingAdjustment::query()->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment?->status)->toBe(BookkeepingAdjustment::STATUS_SUGGESTED)
        ->and($adjustment?->suggested_category_id)->toBe($expenseCategory->id)
        ->and((float) $adjustment?->confidence)->toBe(0.72);
});

it('uses inferred business account scope for bookkeeping cleanup when accounts are not explicitly marked business', function () {
    $user = User::factory()->create();
    $businessChecking = PersonalAccount::factory()->checking()->create([
        'user_id' => $user->id,
        'name' => 'Incoming/deposits',
        'institution_name' => 'Unknown',
        'is_business' => false,
    ]);
    $personalChecking = PersonalAccount::factory()->checking()->create([
        'user_id' => $user->id,
        'name' => 'Household Checking',
        'institution_name' => 'Chase',
        'is_business' => false,
    ]);
    $incomeCategory = TransactionCategory::factory()->income()->create([
        'name' => 'Sales Income',
    ]);

    $businessTransaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $businessChecking->id,
        'transaction_date' => '2026-02-02',
        'amount' => 2400,
        'merchant_name' => 'Client ACH',
        'description' => 'Client ACH payment',
        'category_id' => null,
    ]);
    $personalTransaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $personalChecking->id,
        'transaction_date' => '2026-02-02',
        'amount' => 1800,
        'merchant_name' => 'Family Transfer',
        'description' => 'Family transfer',
        'category_id' => null,
    ]);

    $this->mock(ClaudeCliService::class, function ($mock) use ($businessTransaction, $incomeCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->once()
            ->andReturn([
                'classifications' => [
                    [
                        'transaction_id' => $businessTransaction->id,
                        'category_id' => $incomeCategory->id,
                        'confidence' => 0.91,
                        'rationale' => 'Client ACH payment into the business deposits account.',
                    ],
                ],
            ]);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(1)
        ->and($businessTransaction->fresh()->category_id)->toBe($incomeCategory->id)
        ->and($personalTransaction->fresh()->category_id)->toBeNull();
});

it('batches large bookkeeping ai cleanup runs into multiple requests', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->checking()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);

    $transactions = collect(range(1, 41))->map(function (int $index) use ($account) {
        return PersonalTransaction::factory()->create([
            'personal_account_id' => $account->id,
            'transaction_date' => '2026-02-02',
            'amount' => -20 - $index,
            'merchant_name' => "Vendor {$index}",
            'description' => "Software subscription {$index}",
            'category_id' => null,
        ]);
    });

    $this->mock(ClaudeCliService::class, function ($mock) use ($expenseCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->times(3)
            ->andReturnUsing(function (string $prompt) use ($expenseCategory): array {
                $payload = json_decode($prompt, true);

                return [
                    'classifications' => collect($payload['transactions'] ?? [])
                        ->map(fn (array $transaction): array => [
                            'transaction_id' => $transaction['transaction_id'],
                            'category_id' => $expenseCategory->id,
                            'confidence' => 0.91,
                            'rationale' => 'Recurring software subscription expense.',
                        ])
                        ->all(),
                ];
            });
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(41)
        ->and($transactions->every(fn (PersonalTransaction $transaction): bool => $transaction->fresh()->category_id === $expenseCategory->id))
        ->toBeTrue();
});

it('splits and retries bookkeeping ai batches when a batch times out', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->checking()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);
    $transactions = collect(range(1, 2))->map(function (int $index) use ($account) {
        return PersonalTransaction::factory()->create([
            'personal_account_id' => $account->id,
            'transaction_date' => '2026-02-02',
            'amount' => -50 - $index,
            'merchant_name' => "Timed Vendor {$index}",
            'description' => "Timed subscription {$index}",
            'category_id' => null,
        ]);
    });

    $this->mock(ClaudeCliService::class, function ($mock) use ($expenseCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->times(3)
            ->andReturnUsing(function (string $prompt) use ($expenseCategory): array {
                static $attempt = 0;
                $attempt++;

                if ($attempt === 1) {
                    throw new RuntimeException('The process exceeded the timeout of 60 seconds.');
                }

                $payload = json_decode($prompt, true);

                return [
                    'classifications' => collect($payload['transactions'] ?? [])
                        ->map(fn (array $transaction): array => [
                            'transaction_id' => $transaction['transaction_id'],
                            'category_id' => $expenseCategory->id,
                            'confidence' => 0.9,
                            'rationale' => 'Recurring software subscription expense.',
                        ])
                        ->all(),
                ];
            });
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(2)
        ->and($transactions->every(fn (PersonalTransaction $transaction): bool => $transaction->fresh()->category_id === $expenseCategory->id))
        ->toBeTrue();
});

it('deterministic bookkeeping cleanup revisits paypal instant transfer purchases when the description implies a better category', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->checking()->create([
        'user_id' => $user->id,
        'name' => 'Outgoing/expenses',
        'institution_name' => 'Unknown',
        'is_business' => false,
    ]);
    $softwareCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);
    $loanCategory = TransactionCategory::factory()->create([
        'name' => 'Loan Payment',
        'type' => 'debt_payment',
    ]);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-02-02',
        'amount' => -12.03,
        'merchant_name' => 'PAYPAL',
        'description' => 'PAYPAL INST XFER 251228 GITHUB INC ZAO WEB DESIGN',
        'category_id' => $loanCategory->id,
    ]);

    $this->mock(ClaudeCliService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(false);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(1)
        ->and($transaction->fresh()->category_id)->toBe($softwareCategory->id);
});

it('deterministic bookkeeping cleanup reclassifies greenlight transfers to business and personal movement', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->checking()->business()->create([
        'user_id' => $user->id,
        'name' => 'Incoming/deposits',
    ]);
    $businessPersonalCategory = TransactionCategory::factory()->create([
        'name' => 'Business <> Personal',
        'type' => 'transfer',
    ]);
    $loanCategory = TransactionCategory::factory()->create([
        'name' => 'Loan Payment',
        'type' => 'debt_payment',
    ]);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-02-02',
        'amount' => -45.00,
        'merchant_name' => 'GREENLIGHT',
        'description' => 'GREENLIGHT APP 250901 GREENLIGHT JUSTIN SAINTON',
        'category_id' => $loanCategory->id,
    ]);

    $this->mock(ClaudeCliService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(false);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(1)
        ->and($transaction->fresh()->category_id)->toBe($businessPersonalCategory->id);
});

it('auto-applies repeated merchant ai suggestions when the category consensus is stable', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->checking()->business()->create([
        'user_id' => $user->id,
        'name' => 'Outgoing/expenses',
    ]);
    $subscriptionsCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Subscriptions',
    ]);

    $transactions = collect(range(1, 3))->map(function (int $index) use ($account) {
        return PersonalTransaction::factory()->create([
            'personal_account_id' => $account->id,
            'transaction_date' => '2026-02-02',
            'amount' => -45.00,
            'merchant_name' => 'CONSENSUS VENDOR',
            'description' => "RECURRING PAYMENT AUTHORIZED ON 0{$index}/01 CONSENSUS VENDOR",
            'category_id' => null,
        ]);
    });

    $this->mock(ClaudeCliService::class, function ($mock) use ($transactions, $subscriptionsCategory) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('messageJson')
            ->once()
            ->andReturn([
                'classifications' => $transactions
                    ->map(fn (PersonalTransaction $transaction): array => [
                        'transaction_id' => $transaction->id,
                        'category_id' => $subscriptionsCategory->id,
                        'confidence' => 0.66,
                        'rationale' => 'Recurring merchant pattern indicates the same subscription expense.',
                    ])
                    ->all(),
            ]);
    });

    $result = app(TransactionCategorizationService::class)->runBookkeepingCleanup($user->id, 2026);

    expect($result['auto_applied_count'])->toBe(3)
        ->and(BookkeepingAdjustment::query()->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)->count())->toBe(0)
        ->and($transactions->every(fn (PersonalTransaction $transaction): bool => $transaction->fresh()->category_id === $subscriptionsCategory->id))
        ->toBeTrue();
});
