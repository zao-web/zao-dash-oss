<?php

use App\Models\FinancialDocument;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\PersonalFinance\BookkeepingEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps detailed statement coverage to missing closable months', function () {
    $user = User::factory()->create();

    FinancialDocument::factory()->reviewed()->create([
        'user_id' => $user->id,
        'document_type' => 'bank_statement',
        'file_name' => '2026-january-statement.pdf',
        'extracted_data' => [
            'tax_year' => 2026,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ],
    ]);
    FinancialDocument::factory()->reviewed()->create([
        'user_id' => $user->id,
        'document_type' => 'bank_statement',
        'file_name' => '2026-march-statement.pdf',
        'extracted_data' => [
            'tax_year' => 2026,
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ],
    ]);

    $summary = app(BookkeepingEvidenceService::class)->statementCoverage($user->id, 2026, 3);

    expect($summary['coverage_mode'])->toBe('detailed')
        ->and($summary['covered_months'])->toBe([1, 3])
        ->and($summary['missing_closable_months'])->toBe([2])
        ->and($summary['missing_closable_month_labels'])->toBe(['Feb'])
        ->and($summary['close_support_ready'])->toBeFalse();
});

it('flags owner activity that is misclassified as an expense', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Office Supplies',
    ]);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-03-10',
        'amount' => 2500,
        'description' => 'Owner draw transfer',
        'category_id' => $expenseCategory->id,
    ]);

    $summary = app(BookkeepingEvidenceService::class)->structuralMovementSummary(
        collect([$transaction]),
        collect([
            $expenseCategory->id => [
                'type' => (string) $expenseCategory->type,
                'name' => (string) $expenseCategory->name,
                'tax_category' => null,
            ],
        ]),
    );

    expect($summary['owner_activity_review_count'])->toBe(1)
        ->and($summary['owner_equity_review_count'])->toBe(1)
        ->and($summary['transfer_review_count'])->toBe(0)
        ->and($summary['structural_review_count'])->toBe(1)
        ->and($summary['structural_review_total'])->toBe(2500.0);
});

it('distinguishes owner loans and reimbursements from generic transfer cleanup', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Office Supplies',
    ]);

    $loanTransaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-03-11',
        'amount' => 4000,
        'description' => 'Shareholder loan repayment',
        'category_id' => $expenseCategory->id,
    ]);
    $reimbursementTransaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-03-12',
        'amount' => 650,
        'description' => 'Owner reimbursement accountable plan',
        'category_id' => $expenseCategory->id,
    ]);

    $summary = app(BookkeepingEvidenceService::class)->structuralMovementSummary(
        collect([$loanTransaction, $reimbursementTransaction]),
        collect([
            $expenseCategory->id => [
                'type' => (string) $expenseCategory->type,
                'name' => (string) $expenseCategory->name,
                'tax_category' => null,
            ],
        ]),
    );

    expect($summary['loan_activity_review_count'])->toBe(1)
        ->and($summary['reimbursement_review_count'])->toBe(1)
        ->and($summary['transfer_review_count'])->toBe(0)
        ->and($summary['structural_review_count'])->toBe(2)
        ->and($summary['structural_review_total'])->toBe(4650.0);
});

it('does not flag paypal instant transfer purchase descriptions as transfer cleanup once categorized to expense', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $expenseCategory = TransactionCategory::factory()->expense()->create([
        'name' => 'Software/SaaS',
    ]);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-03-13',
        'amount' => -19,
        'merchant_name' => 'PAYPAL',
        'description' => 'PAYPAL INST XFER 251228 GITHUB INC ZAO WEB DESIGN',
        'category_id' => $expenseCategory->id,
    ]);

    $summary = app(BookkeepingEvidenceService::class)->structuralMovementSummary(
        collect([$transaction]),
        collect([
            $expenseCategory->id => [
                'type' => (string) $expenseCategory->type,
                'name' => (string) $expenseCategory->name,
                'tax_category' => null,
            ],
        ]),
    );

    expect($summary['transfer_review_count'])->toBe(0)
        ->and($summary['structural_review_count'])->toBe(0)
        ->and($summary['structural_review_total'])->toBe(0.0);
});

it('does not flag zelle from receipts as transfer cleanup once categorized to income', function () {
    $user = User::factory()->create();
    $account = PersonalAccount::factory()->business()->create([
        'user_id' => $user->id,
    ]);
    $incomeCategory = TransactionCategory::factory()->income()->create([
        'name' => 'Business Income',
    ]);

    $transaction = PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'transaction_date' => '2026-03-14',
        'amount' => 395,
        'merchant_name' => 'ZELLE FROM',
        'description' => 'ZELLE FROM TRENT TONEY ON 03/10 REF # 2BO01ZD1MSW6 MEXICO PAYMENT 1',
        'category_id' => $incomeCategory->id,
    ]);

    $summary = app(BookkeepingEvidenceService::class)->structuralMovementSummary(
        collect([$transaction]),
        collect([
            $incomeCategory->id => [
                'type' => (string) $incomeCategory->type,
                'name' => (string) $incomeCategory->name,
                'tax_category' => null,
            ],
        ]),
    );

    expect($summary['transfer_review_count'])->toBe(0)
        ->and($summary['structural_review_count'])->toBe(0)
        ->and($summary['structural_review_total'])->toBe(0.0);
});
