<?php

namespace App\Services\PersonalFinance;

use App\Models\FinancialDocument;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StatementReconciliationService
{
    public function __construct(
        protected BookkeepingEvidenceService $bookkeepingEvidenceService,
        protected TransactionDirectionService $transactionDirectionService,
    ) {}

    /**
     * @param  Collection<int, PersonalAccount>  $scopedAccounts
     * @param  Collection<int, PersonalTransaction>  $bankTransactions
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     next_action: string,
     *     exact_statement_month_count: int,
     *     reconciled_month_count: int,
     *     missing_exact_month_count: int,
     *     issue_count: int,
     *     line_item_issue_count: int,
     *     unmatched_statement_count: int,
     *     docs_needing_review_count: int,
     *     parsed_statement_line_item_count: int,
     *     matched_statement_line_item_count: int,
     *     unmatched_statement_line_item_count: int,
     *     unmatched_ledger_transaction_count: int,
     *     all_closable_months_reconciled: bool,
     *     months: array<int, array<string, mixed>>,
     * }
     */
    public function summarize(
        int $userId,
        int $year,
        Collection $scopedAccounts,
        Collection $bankTransactions,
        int $closableMonthCount = 0,
    ): array {
        $statementDocuments = $this->statementDocuments($userId, $year);
        $closableMonths = $closableMonthCount > 0 ? range(1, $closableMonthCount) : [];
        $months = collect($closableMonths)
            ->map(fn (int $month): array => $this->monthSummary(
                year: $year,
                month: $month,
                statementDocuments: $statementDocuments,
                scopedAccounts: $scopedAccounts,
                bankTransactions: $bankTransactions,
            ))
            ->all();

        $monthCollection = collect($months);
        $issueCount = $monthCollection
            ->filter(fn (array $month): bool => in_array($month['status'], ['needs_review', 'needs_match'], true))
            ->count();
        $missingExactMonthCount = $monthCollection
            ->filter(fn (array $month): bool => $month['status'] === 'missing')
            ->count();
        $lineItemIssueCount = $monthCollection
            ->filter(fn (array $month): bool => (string) ($month['line_item_status'] ?? 'not_available') === 'needs_review')
            ->count();
        $reconciledMonthCount = $monthCollection
            ->filter(fn (array $month): bool => $month['status'] === 'passed')
            ->count();
        $exactStatementMonthCount = $monthCollection
            ->filter(fn (array $month): bool => (int) $month['exact_statement_count'] > 0)
            ->count();
        $unmatchedStatementCount = (int) $monthCollection->sum('unmatched_statement_count');
        $docsNeedingReviewCount = (int) $monthCollection->sum('docs_needing_review_count');
        $parsedStatementLineItemCount = (int) $monthCollection->sum('parsed_statement_line_item_count');
        $matchedStatementLineItemCount = (int) $monthCollection->sum('matched_statement_line_item_count');
        $unmatchedStatementLineItemCount = (int) $monthCollection->sum('unmatched_statement_line_item_count');
        $unmatchedLedgerTransactionCount = (int) $monthCollection->sum('unmatched_ledger_transaction_count');
        $allClosableMonthsReconciled = $closableMonths === []
            ? true
            : $issueCount === 0 && $missingExactMonthCount === 0;

        $status = match (true) {
            $issueCount > 0 => 'needs_review',
            $missingExactMonthCount > 0 => 'needs_documents',
            $exactStatementMonthCount === 0 => 'not_available',
            default => 'passed',
        };

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'passed' => 'Reconciled',
                'needs_review' => 'Needs review',
                'needs_documents' => 'Needs exact statements',
                default => 'Not available',
            },
            'summary' => match ($status) {
                'passed' => 'Statement deposits, withdrawals, and ending balances reconcile against the bank ledger for every ended month.',
                'needs_review' => $lineItemIssueCount > 0
                    ? "{$lineItemIssueCount} statement month(s) still need line-item verification against the ledger."
                    : "{$issueCount} statement month(s) disagree with the bank ledger or still need account matching.",
                'needs_documents' => "{$missingExactMonthCount} ended month(s) still need exact monthly statement extraction before the close is airtight.",
                default => 'Monthly statements are not detailed enough yet to reconcile the ledger.',
            },
            'next_action' => match ($status) {
                'passed' => 'Keep importing reviewed monthly statements so each ended month keeps reconciling cleanly.',
                'needs_review' => $lineItemIssueCount > 0
                    ? 'Reprocess or review the monthly statements until line-item coverage matches the ledger.'
                    : 'Review the mismatched statement months before trusting the internal books.',
                'needs_documents' => 'Upload or reprocess exact monthly bank statements for the missing ended months.',
                default => 'Import reviewed monthly bank statements with balances, deposits, and withdrawals extracted.',
            },
            'exact_statement_month_count' => $exactStatementMonthCount,
            'reconciled_month_count' => $reconciledMonthCount,
            'missing_exact_month_count' => $missingExactMonthCount,
            'issue_count' => $issueCount,
            'line_item_issue_count' => $lineItemIssueCount,
            'unmatched_statement_count' => $unmatchedStatementCount,
            'docs_needing_review_count' => $docsNeedingReviewCount,
            'parsed_statement_line_item_count' => $parsedStatementLineItemCount,
            'matched_statement_line_item_count' => $matchedStatementLineItemCount,
            'unmatched_statement_line_item_count' => $unmatchedStatementLineItemCount,
            'unmatched_ledger_transaction_count' => $unmatchedLedgerTransactionCount,
            'all_closable_months_reconciled' => $allClosableMonthsReconciled,
            'months' => $months,
        ];
    }

    /**
     * @param  Collection<int, FinancialDocument>  $statementDocuments
     * @param  Collection<int, PersonalAccount>  $scopedAccounts
     * @param  Collection<int, PersonalTransaction>  $bankTransactions
     * @return array<string, mixed>
     */
    public function monthSummary(
        int $year,
        int $month,
        Collection $statementDocuments,
        Collection $scopedAccounts,
        Collection $bankTransactions,
    ): array {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        $monthlyStatements = $statementDocuments
            ->map(function (FinancialDocument $document) use ($year): ?array {
                $period = $this->statementPeriod($document, $year);

                if ($period === null) {
                    return null;
                }

                return [
                    'document' => $document,
                    'period' => $period,
                ];
            })
            ->filter(fn (?array $payload): bool => is_array($payload))
            ->filter(fn (array $payload): bool => ($payload['period']['month'] ?? null) === $month)
            ->values();

        if ($monthlyStatements->isEmpty()) {
            return [
                'month' => $month,
                'full_label' => $startDate->format('F Y'),
                'status' => 'missing',
                'status_label' => 'Missing exact statement',
                'detail' => "No reviewed monthly statement with balances was found for {$startDate->format('F Y')}.",
                'exact_statement_count' => 0,
                'docs_needing_review_count' => 0,
                'matched_account_count' => 0,
                'unmatched_statement_count' => 0,
                'statement_deposits' => 0.0,
                'statement_withdrawals' => 0.0,
                'statement_ending_balance' => null,
                'ledger_deposits' => 0.0,
                'ledger_withdrawals' => 0.0,
                'ledger_ending_balance' => null,
                'deposit_difference' => null,
                'withdrawal_difference' => null,
                'ending_balance_difference' => null,
                'line_item_status' => 'missing',
                'line_item_status_label' => 'Missing extraction',
                'line_item_detail' => "Statement line items are not available for {$startDate->format('F Y')}.",
                'parsed_statement_line_item_count' => 0,
                'matched_statement_line_item_count' => 0,
                'unmatched_statement_line_item_count' => 0,
                'unmatched_ledger_transaction_count' => 0,
                'passes' => false,
                'blockers' => ["No exact monthly statement is on file for {$startDate->format('F Y')}."],
            ];
        }

        $matchedAccounts = collect();
        $unmatchedStatementCount = 0;
        $docsNeedingReviewCount = 0;
        $statementDeposits = 0.0;
        $statementWithdrawals = 0.0;
        $statementEndingBalance = 0.0;
        $hasStatementEndingBalance = false;

        foreach ($monthlyStatements as $statement) {
            /** @var FinancialDocument $document */
            $document = $statement['document'];
            $matchedAccount = $this->matchAccount($document, $scopedAccounts);

            if ($matchedAccount instanceof PersonalAccount) {
                $matchedAccounts->push($matchedAccount);
            } else {
                $unmatchedStatementCount++;
            }

            if ($this->statementDocumentNeedsReview($document)) {
                $docsNeedingReviewCount++;
            }

            $statementDeposits += $this->statementNumericValue($document, 'total_deposits') ?? 0.0;
            $statementWithdrawals += $this->statementNumericValue($document, 'total_withdrawals') ?? 0.0;

            $documentEndingBalance = $this->statementNumericValue($document, 'ending_balance');

            if ($documentEndingBalance !== null) {
                $statementEndingBalance += $documentEndingBalance;
                $hasStatementEndingBalance = true;
            }
        }

        $matchedAccountIds = $matchedAccounts
            ->unique('id')
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $ledgerTransactions = $matchedAccountIds === []
            ? collect()
            : $bankTransactions
                ->whereIn('personal_account_id', $matchedAccountIds)
                ->filter(fn (PersonalTransaction $transaction): bool => $transaction->transaction_date->betweenIncluded($startDate, $endDate))
                ->values();

        $ledgerDeposits = $this->absoluteTransactionSum(
            $ledgerTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction))
                ->pluck('amount')
        );
        $ledgerWithdrawals = $this->absoluteTransactionSum(
            $ledgerTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction))
                ->pluck('amount')
        );
        $ledgerEndingBalance = $matchedAccountIds === []
            ? null
            : $this->derivedEndingBalance($matchedAccounts->unique('id')->values(), $bankTransactions, $endDate);

        $depositDifference = abs($statementDeposits - $ledgerDeposits);
        $withdrawalDifference = abs($statementWithdrawals - $ledgerWithdrawals);
        $endingBalanceDifference = $hasStatementEndingBalance && $ledgerEndingBalance !== null
            ? abs($statementEndingBalance - $ledgerEndingBalance)
            : null;
        $lineItemVerification = $this->lineItemVerification(
            monthlyStatements: $monthlyStatements,
            year: $year,
            month: $month,
            ledgerTransactions: $ledgerTransactions,
            statementDeposits: $statementDeposits,
            statementWithdrawals: $statementWithdrawals,
        );

        $blockers = [];

        if ($docsNeedingReviewCount > 0) {
            $blockers[] = "{$docsNeedingReviewCount} statement document(s) still need review.";
        }

        if ($unmatchedStatementCount > 0) {
            $blockers[] = "{$unmatchedStatementCount} statement document(s) could not be matched to a live bank account.";
        }

        if ($depositDifference > $this->tolerance($statementDeposits)) {
            $blockers[] = 'Statement deposits do not tie to the ledger.';
        }

        if ($withdrawalDifference > $this->tolerance($statementWithdrawals)) {
            $blockers[] = 'Statement withdrawals do not tie to the ledger.';
        }

        if ($endingBalanceDifference !== null && $endingBalanceDifference > $this->tolerance($statementEndingBalance, 0.005, 25.0)) {
            $blockers[] = 'Statement ending balance does not tie to the ledger.';
        }

        foreach (($lineItemVerification['blockers'] ?? []) as $lineItemBlocker) {
            if (is_string($lineItemBlocker) && $lineItemBlocker !== '') {
                $blockers[] = $lineItemBlocker;
            }
        }

        $status = match (true) {
            $docsNeedingReviewCount > 0 || $depositDifference > $this->tolerance($statementDeposits) || $withdrawalDifference > $this->tolerance($statementWithdrawals) || ($endingBalanceDifference !== null && $endingBalanceDifference > $this->tolerance($statementEndingBalance, 0.005, 25.0)) => 'needs_review',
            (string) ($lineItemVerification['status'] ?? 'not_available') === 'needs_review' => 'needs_review',
            $unmatchedStatementCount > 0 => 'needs_match',
            default => 'passed',
        };

        return [
            'month' => $month,
            'full_label' => $startDate->format('F Y'),
            'status' => $status,
            'status_label' => match ($status) {
                'passed' => 'Reconciled',
                'needs_match' => 'Needs account match',
                default => 'Needs review',
            },
            'detail' => match ($status) {
                'passed' => "Statement activity reconciles for {$startDate->format('F Y')}.",
                'needs_match' => "Statement activity is on file for {$startDate->format('F Y')}, but one or more statements could not be matched to a live bank account.",
                default => "Statement activity for {$startDate->format('F Y')} does not reconcile cleanly yet.",
            },
            'exact_statement_count' => $monthlyStatements->count(),
            'docs_needing_review_count' => $docsNeedingReviewCount,
            'matched_account_count' => count($matchedAccountIds),
            'unmatched_statement_count' => $unmatchedStatementCount,
            'statement_deposits' => round($statementDeposits, 2),
            'statement_withdrawals' => round($statementWithdrawals, 2),
            'statement_ending_balance' => $hasStatementEndingBalance ? round($statementEndingBalance, 2) : null,
            'ledger_deposits' => round($ledgerDeposits, 2),
            'ledger_withdrawals' => round($ledgerWithdrawals, 2),
            'ledger_ending_balance' => $ledgerEndingBalance !== null ? round($ledgerEndingBalance, 2) : null,
            'deposit_difference' => round($depositDifference, 2),
            'withdrawal_difference' => round($withdrawalDifference, 2),
            'ending_balance_difference' => $endingBalanceDifference !== null ? round($endingBalanceDifference, 2) : null,
            'line_item_status' => (string) ($lineItemVerification['status'] ?? 'not_available'),
            'line_item_status_label' => (string) ($lineItemVerification['status_label'] ?? 'Not available'),
            'line_item_detail' => (string) ($lineItemVerification['detail'] ?? 'Statement line items are not available yet.'),
            'parsed_statement_line_item_count' => (int) ($lineItemVerification['parsed_statement_line_item_count'] ?? 0),
            'matched_statement_line_item_count' => (int) ($lineItemVerification['matched_statement_line_item_count'] ?? 0),
            'unmatched_statement_line_item_count' => (int) ($lineItemVerification['unmatched_statement_line_item_count'] ?? 0),
            'unmatched_ledger_transaction_count' => (int) ($lineItemVerification['unmatched_ledger_transaction_count'] ?? 0),
            'passes' => $status === 'passed',
            'blockers' => $blockers,
        ];
    }

    /**
     * @return Collection<int, FinancialDocument>
     */
    protected function statementDocuments(int $userId, int $year): Collection
    {
        return FinancialDocument::query()
            ->where('user_id', $userId)
            ->whereIn('document_type', ['bank_statement', 'credit_card_statement'])
            ->get()
            ->filter(fn (FinancialDocument $document): bool => $this->statementMatchesYear($document, $year))
            ->groupBy(fn (FinancialDocument $document): string => $this->statementDocumentIdentityKey($document, $year))
            ->map(fn (Collection $documents): FinancialDocument => $this->preferredStatementDocument($documents))
            ->values();
    }

    protected function statementMatchesYear(FinancialDocument $document, int $year): bool
    {
        $taxYear = $this->documentTaxYear($document);

        if ($taxYear !== null) {
            return $taxYear === $year;
        }

        $period = $this->statementPeriod($document, $year);

        if ($period !== null) {
            return true;
        }

        return $document->effective_date?->year === $year
            || str_contains($document->file_name, (string) $year);
    }

    /**
     * @return array{start: Carbon, end: Carbon, month: int}|null
     */
    protected function statementPeriod(FinancialDocument $document, int $year): ?array
    {
        $startDate = $this->statementDateValue($document, 'period_start');
        $endDate = $this->statementDateValue($document, 'period_end');

        if (! $startDate instanceof Carbon || ! $endDate instanceof Carbon) {
            return null;
        }

        if ($startDate->year !== $year || $endDate->year !== $year) {
            return null;
        }

        if ($startDate->month !== $endDate->month) {
            return null;
        }

        return [
            'start' => $startDate->copy()->startOfDay(),
            'end' => $endDate->copy()->endOfDay(),
            'month' => $startDate->month,
        ];
    }

    protected function statementDateValue(FinancialDocument $document, string $key): ?Carbon
    {
        $value = data_get($document->extracted_data, $key)
            ?? data_get($document->extracted_data, "extracted_fields.{$key}");

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function documentTaxYear(FinancialDocument $document): ?int
    {
        $taxYear = data_get($document->extracted_data, 'tax_year')
            ?? data_get($document->extracted_data, 'extracted_fields.tax_year');

        return is_numeric($taxYear) ? (int) $taxYear : null;
    }

    protected function statementNumericValue(FinancialDocument $document, string $key): ?float
    {
        $value = data_get($document->extracted_data, $key)
            ?? data_get($document->extracted_data, "extracted_fields.{$key}");

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * @param  Collection<int, PersonalAccount>  $scopedAccounts
     */
    protected function matchAccount(FinancialDocument $document, Collection $scopedAccounts): ?PersonalAccount
    {
        if ($scopedAccounts->count() === 1) {
            return $scopedAccounts->first();
        }

        $documentLast4 = $this->statementDocumentLast4($document);
        $documentInstitution = $this->statementDocumentInstitution($document) ?? '';

        $matches = $scopedAccounts
            ->filter(function (PersonalAccount $account) use ($documentInstitution, $documentLast4): bool {
                if ($documentInstitution !== '' && ! $this->statementInstitutionMatches($account, $documentInstitution)) {
                    return false;
                }

                if ($documentLast4 === null) {
                    return true;
                }

                return $this->accountLast4($account) === $documentLast4;
            })
            ->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($documentInstitution !== '') {
            $institutionMatches = $scopedAccounts
                ->filter(fn (PersonalAccount $account): bool => $this->statementInstitutionMatches($account, $documentInstitution))
                ->values();

            if ($institutionMatches->count() === 1) {
                return $institutionMatches->first();
            }
        }

        return null;
    }

    protected function statementTextValue(FinancialDocument $document, string $key): ?string
    {
        $value = data_get($document->extracted_data, $key)
            ?? data_get($document->extracted_data, "extracted_fields.{$key}");

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    protected function statementDocumentLast4(FinancialDocument $document): ?string
    {
        $explicitLast4 = $this->statementTextValue($document, 'account_last4');

        if ($explicitLast4 !== null && preg_match('/(\d{4})$/', $explicitLast4, $matches) === 1) {
            return $matches[1];
        }

        foreach ([$document->file_name, data_get($document->extracted_data, 'archive_relative_path')] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (preg_match('/\((\d{4})\)/', $candidate, $matches) === 1) {
                return $matches[1];
            }

            if (preg_match('/(?:ending in|acct|account|x{2,})[^0-9]*(\d{4})/i', $candidate, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function statementDocumentInstitution(FinancialDocument $document): ?string
    {
        $explicitInstitution = $this->statementTextValue($document, 'institution');

        if ($explicitInstitution !== null) {
            return strtolower($explicitInstitution);
        }

        $searchText = strtolower(collect([
            $document->file_name,
            data_get($document->extracted_data, 'archive_relative_path'),
        ])->filter()->implode(' '));

        foreach (['wells fargo', 'wellsfargo', 'chase', 'capital one', 'capitalone', 'american express', 'amex'] as $institution) {
            if (str_contains($searchText, $institution)) {
                return str_replace(' ', '', $institution) === 'wellsfargo' ? 'wellsfargo' : $institution;
            }
        }

        return null;
    }

    protected function statementDocumentNeedsReview(FinancialDocument $document): bool
    {
        if (! $document->needs_review) {
            return false;
        }

        if ($document->processing_status !== 'completed') {
            return true;
        }

        $hasPeriod = $this->statementTextValue($document, 'period_start') !== null
            && $this->statementTextValue($document, 'period_end') !== null;
        $hasIdentity = $this->statementDocumentLast4($document) !== null
            || $this->statementDocumentInstitution($document) !== null;
        $hasTotals = $this->statementNumericValue($document, 'total_deposits') !== null
            && $this->statementNumericValue($document, 'total_withdrawals') !== null;
        $hasBalances = $this->statementNumericValue($document, 'opening_balance') !== null
            && $this->statementNumericValue($document, 'ending_balance') !== null;
        $usableForMatching = (bool) data_get($document->extracted_data, 'line_item_summary.usable_for_matching');

        return ! (
            ($usableForMatching && $hasPeriod && $hasIdentity && ($hasTotals || $hasBalances))
            || ($hasPeriod && $hasIdentity && ($hasTotals || $hasBalances))
        );
    }

    protected function statementDocumentIdentityKey(FinancialDocument $document, int $year): string
    {
        $period = $this->statementPeriod($document, $year);
        $month = $period['month'] ?? 'unknown';
        $last4 = $this->statementDocumentLast4($document) ?? 'unknown';
        $institution = $this->statementDocumentInstitution($document) ?? 'unknown';
        $type = (string) $document->document_type;
        $fallbackName = Str::lower(pathinfo($document->file_name, PATHINFO_FILENAME));

        return implode('|', [
            $type,
            (string) $year,
            (string) $month,
            $institution,
            $last4,
            $last4 === 'unknown' && $institution === 'unknown' ? $fallbackName : 'matched',
        ]);
    }

    /**
     * @param  Collection<int, FinancialDocument>  $documents
     */
    protected function preferredStatementDocument(Collection $documents): FinancialDocument
    {
        return $documents
            ->sort(function (FinancialDocument $left, FinancialDocument $right): int {
                return $this->statementDocumentRank($right) <=> $this->statementDocumentRank($left);
            })
            ->first();
    }

    protected function statementDocumentRank(FinancialDocument $document): int
    {
        $rank = 0;

        if ($document->processing_status === 'completed') {
            $rank += 1000;
        }

        if (! $this->statementDocumentNeedsReview($document)) {
            $rank += 100;
        }

        $rank += (int) round(((float) ($document->extraction_confidence ?? 0)) * 100);
        $rank += $document->reviewed_at?->timestamp ?? 0;
        $rank += $document->created_at?->timestamp ?? 0;

        return $rank;
    }

    protected function statementInstitutionMatches(PersonalAccount $account, string $documentInstitution): bool
    {
        $accountInstitution = str_replace(' ', '', strtolower((string) $account->institution_name));
        $normalizedDocumentInstitution = str_replace(' ', '', strtolower($documentInstitution));

        return $accountInstitution !== '' && $normalizedDocumentInstitution !== ''
            ? str_contains($accountInstitution, $normalizedDocumentInstitution)
            : false;
    }

    protected function accountLast4(PersonalAccount $account): ?string
    {
        $metadataCandidates = [
            data_get($account->metadata, 'mask'),
            data_get($account->metadata, 'last4'),
            data_get($account->metadata, 'account_last4'),
        ];

        foreach ($metadataCandidates as $candidate) {
            if (is_string($candidate) && preg_match('/(\d{4})$/', $candidate, $matches) === 1) {
                return $matches[1];
            }
        }

        if (preg_match('/(\d{4})/', $account->name, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  Collection<int, PersonalAccount>  $accounts
     * @param  Collection<int, PersonalTransaction>  $allTransactions
     */
    protected function derivedEndingBalance(Collection $accounts, Collection $allTransactions, Carbon $statementEndDate): ?float
    {
        if ($accounts->isEmpty()) {
            return null;
        }

        return round((float) $accounts->sum(function (PersonalAccount $account) use ($allTransactions, $statementEndDate): float {
            $postStatementActivity = $allTransactions
                ->where('personal_account_id', $account->id)
                ->filter(fn (PersonalTransaction $transaction): bool => $transaction->transaction_date->gt($statementEndDate))
                ->sum('amount');

            return (float) $account->current_balance - (float) $postStatementActivity;
        }), 2);
    }

    protected function tolerance(float $referenceAmount, float $percent = 0.01, float $minimum = 15.0): float
    {
        return max($minimum, abs($referenceAmount) * $percent);
    }

    /**
     * @param  Collection<int, array{document: FinancialDocument, period: array{start: Carbon, end: Carbon, month: int}}>  $monthlyStatements
     * @param  Collection<int, PersonalTransaction>  $ledgerTransactions
     * @return array{
     *     status: string,
     *     status_label: string,
     *     detail: string,
     *     parsed_statement_line_item_count: int,
     *     matched_statement_line_item_count: int,
     *     unmatched_statement_line_item_count: int,
     *     unmatched_ledger_transaction_count: int,
     *     blockers: array<int, string>,
     * }
     */
    protected function lineItemVerification(
        Collection $monthlyStatements,
        int $year,
        int $month,
        Collection $ledgerTransactions,
        float $statementDeposits,
        float $statementWithdrawals,
    ): array {
        $statementLineItems = collect();
        $missingExtractionCount = 0;

        foreach ($monthlyStatements as $statement) {
            $documentLineItems = $this->statementLineItems($statement['document'], $year, $month);

            if ($documentLineItems->isEmpty()) {
                $missingExtractionCount++;
            }

            $statementLineItems = $statementLineItems->merge($documentLineItems);
        }

        $parsedCount = $statementLineItems->count();
        $blockers = [];

        if ($missingExtractionCount > 0) {
            $blockers[] = "{$missingExtractionCount} reviewed statement document(s) still need line-item extraction.";
        }

        if ($parsedCount === 0) {
            if (abs($statementDeposits) < 0.01 && abs($statementWithdrawals) < 0.01 && $ledgerTransactions->isEmpty()) {
                return [
                    'status' => 'passed',
                    'status_label' => 'Verified',
                    'detail' => 'No transaction activity appears on the statement or in the imported ledger for this month.',
                    'parsed_statement_line_item_count' => 0,
                    'matched_statement_line_item_count' => 0,
                    'unmatched_statement_line_item_count' => 0,
                    'unmatched_ledger_transaction_count' => 0,
                    'blockers' => [],
                ];
            }

            return [
                'status' => 'missing',
                'status_label' => 'Missing extraction',
                'detail' => 'Statement line items are not available yet for exact feed verification.',
                'parsed_statement_line_item_count' => 0,
                'matched_statement_line_item_count' => 0,
                'unmatched_statement_line_item_count' => 0,
                'unmatched_ledger_transaction_count' => 0,
                'blockers' => $blockers === [] ? ['Statement line items are not extracted yet.'] : $blockers,
            ];
        }

        $parsedDeposits = round((float) $statementLineItems
            ->where('direction', 'inflow')
            ->sum('amount'), 2);
        $parsedWithdrawals = round((float) $statementLineItems
            ->where('direction', 'outflow')
            ->sum('amount'), 2);
        $unknownDirectionCount = $statementLineItems
            ->where('direction', 'unknown')
            ->count();
        $depositDifference = abs($statementDeposits - $parsedDeposits);
        $withdrawalDifference = abs($statementWithdrawals - $parsedWithdrawals);

        if ($unknownDirectionCount > 0) {
            $blockers[] = "{$unknownDirectionCount} parsed statement line item(s) still need inflow/outflow direction.";
        }

        if ($depositDifference > $this->tolerance($statementDeposits)) {
            $blockers[] = 'Parsed statement line items do not tie to statement deposit totals.';
        }

        if ($withdrawalDifference > $this->tolerance($statementWithdrawals)) {
            $blockers[] = 'Parsed statement line items do not tie to statement withdrawal totals.';
        }

        if ($blockers !== []) {
            return [
                'status' => 'needs_review',
                'status_label' => 'Needs line-item review',
                'detail' => 'Statement line items are present, but they do not yet prove exact transaction coverage.',
                'parsed_statement_line_item_count' => $parsedCount,
                'matched_statement_line_item_count' => 0,
                'unmatched_statement_line_item_count' => $parsedCount,
                'unmatched_ledger_transaction_count' => $ledgerTransactions->count(),
                'blockers' => $blockers,
            ];
        }

        $matching = $this->matchStatementLineItems($statementLineItems, $ledgerTransactions);

        if ((int) $matching['unmatched_statement_line_item_count'] > 0) {
            $blockers[] = "{$matching['unmatched_statement_line_item_count']} statement line item(s) were not found in the imported ledger.";
        }

        if ((int) $matching['unmatched_ledger_transaction_count'] > 0) {
            $blockers[] = "{$matching['unmatched_ledger_transaction_count']} imported ledger transaction(s) were not found on the statement.";
        }

        return [
            'status' => $blockers === [] ? 'passed' : 'needs_review',
            'status_label' => $blockers === [] ? 'Verified' : 'Needs line-item review',
            'detail' => $blockers === []
                ? 'Statement line items match the imported ledger for this month.'
                : 'Statement line items and imported ledger still disagree.',
            'parsed_statement_line_item_count' => $parsedCount,
            'matched_statement_line_item_count' => (int) $matching['matched_statement_line_item_count'],
            'unmatched_statement_line_item_count' => (int) $matching['unmatched_statement_line_item_count'],
            'unmatched_ledger_transaction_count' => (int) $matching['unmatched_ledger_transaction_count'],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return Collection<int, array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }>
     */
    protected function statementLineItems(FinancialDocument $document, int $year, int $month): Collection
    {
        $lineItems = data_get($document->extracted_data, 'line_items');

        if (! is_array($lineItems)) {
            return collect();
        }

        return collect($lineItems)
            ->filter(function (mixed $lineItem) use ($year, $month): bool {
                if (! is_array($lineItem) || ! is_string($lineItem['transaction_date'] ?? null)) {
                    return false;
                }

                try {
                    $transactionDate = Carbon::parse($lineItem['transaction_date']);
                } catch (\Throwable) {
                    return false;
                }

                return $transactionDate->year === $year
                    && $transactionDate->month === $month
                    && is_numeric($lineItem['amount'] ?? null)
                    && is_string($lineItem['description'] ?? null);
            })
            ->map(fn (array $lineItem): array => [
                'transaction_date' => (string) $lineItem['transaction_date'],
                'description' => (string) $lineItem['description'],
                'amount' => round(abs((float) $lineItem['amount']), 2),
                'signed_amount' => is_numeric($lineItem['signed_amount'] ?? null) ? round((float) $lineItem['signed_amount'], 2) : null,
                'direction' => in_array($lineItem['direction'] ?? null, ['inflow', 'outflow'], true)
                    ? (string) $lineItem['direction']
                    : 'unknown',
                'balance' => is_numeric($lineItem['balance'] ?? null) ? round((float) $lineItem['balance'], 2) : null,
                'raw_line' => (string) ($lineItem['raw_line'] ?? ''),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }>  $statementLineItems
     * @param  Collection<int, PersonalTransaction>  $ledgerTransactions
     * @return array{
     *     matched_statement_line_item_count: int,
     *     unmatched_statement_line_item_count: int,
     *     unmatched_ledger_transaction_count: int,
     * }
     */
    protected function matchStatementLineItems(Collection $statementLineItems, Collection $ledgerTransactions): array
    {
        $usedTransactionIds = [];
        $matchedStatementLineItemCount = 0;
        $unmatchedStatementLineItemCount = 0;

        foreach ($statementLineItems->values() as $lineItem) {
            $bestTransaction = null;
            $bestScore = null;

            foreach ($ledgerTransactions as $transaction) {
                if (isset($usedTransactionIds[$transaction->id])) {
                    continue;
                }

                if (! $this->lineItemCouldMatchTransaction($lineItem, $transaction)) {
                    continue;
                }

                $score = $this->lineItemMatchScore($lineItem, $transaction);

                if ($bestScore === null || $score > $bestScore) {
                    $bestScore = $score;
                    $bestTransaction = $transaction;
                }
            }

            if (! $bestTransaction instanceof PersonalTransaction) {
                $unmatchedStatementLineItemCount++;

                continue;
            }

            $usedTransactionIds[$bestTransaction->id] = true;
            $matchedStatementLineItemCount++;
        }

        return [
            'matched_statement_line_item_count' => $matchedStatementLineItemCount,
            'unmatched_statement_line_item_count' => $unmatchedStatementLineItemCount,
            'unmatched_ledger_transaction_count' => max(0, $ledgerTransactions->count() - count($usedTransactionIds)),
        ];
    }

    /**
     * @param  array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }  $lineItem
     */
    protected function lineItemCouldMatchTransaction(array $lineItem, PersonalTransaction $transaction): bool
    {
        $transactionAmount = round(abs((float) $transaction->amount), 2);

        if (abs($transactionAmount - (float) $lineItem['amount']) > 0.01) {
            return false;
        }

        $statementDate = Carbon::parse($lineItem['transaction_date']);
        $dateDifference = abs($statementDate->diffInDays($transaction->transaction_date, false));

        if ($dateDifference > 3) {
            return false;
        }

        if ($lineItem['direction'] !== 'unknown') {
            $transactionDirection = $this->transactionDirectionService->isInflow($transaction)
                ? 'inflow'
                : 'outflow';

            if ($transactionDirection !== $lineItem['direction']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }  $lineItem
     */
    protected function lineItemMatchScore(array $lineItem, PersonalTransaction $transaction): int
    {
        $statementDate = Carbon::parse($lineItem['transaction_date']);
        $dateDifference = abs($statementDate->diffInDays($transaction->transaction_date, false));
        $score = 100 - ($dateDifference * 15);

        $statementDescription = $this->normalizedDescription((string) $lineItem['description']);
        $ledgerDescription = $this->normalizedDescription(trim(
            implode(' ', array_filter([
                $transaction->merchant_name,
                $transaction->description,
            ]))
        ));

        if ($statementDescription !== '' && $ledgerDescription !== '') {
            if ($statementDescription === $ledgerDescription) {
                return $score + 50;
            }

            if (str_contains($statementDescription, $ledgerDescription) || str_contains($ledgerDescription, $statementDescription)) {
                return $score + 35;
            }

            $statementTokens = $this->descriptionTokens($statementDescription);
            $ledgerTokens = $this->descriptionTokens($ledgerDescription);
            $overlap = count(array_intersect($statementTokens, $ledgerTokens));

            $score += min(30, $overlap * 6);
        }

        return $score;
    }

    protected function normalizedDescription(string $value): string
    {
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9 ]+/i', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    protected function descriptionTokens(string $description): array
    {
        $stopWords = [
            'the', 'and', 'for', 'with', 'card', 'visa', 'debit', 'credit',
            'authorized', 'purchase', 'online', 'transfer', 'payment', 'from',
            'to', 'llc', 'inc', 'on', 'ach',
        ];

        return array_values(array_filter(
            explode(' ', $description),
            fn (string $token): bool => strlen($token) >= 4 && ! in_array($token, $stopWords, true),
        ));
    }

    /**
     * @param  Collection<int, mixed>  $amounts
     */
    protected function absoluteTransactionSum(Collection $amounts): float
    {
        return (float) $amounts->reduce(
            fn (float $sum, mixed $amount): float => $sum + abs((float) $amount),
            0.0,
        );
    }
}
