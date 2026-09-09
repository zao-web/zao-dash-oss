<?php

namespace App\Services\PersonalFinance;

use App\Models\BookkeepingAdjustment;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use App\Models\TransactionCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BookkeepingReadinessService
{
    public function __construct(
        protected BookkeepingPeriodCloseService $bookkeepingPeriodCloseService,
        protected BookkeepingEvidenceService $bookkeepingEvidenceService,
        protected StatementReconciliationService $statementReconciliationService,
        protected BookkeepingAccountScopeService $bookkeepingAccountScopeService,
        protected TransactionDirectionService $transactionDirectionService,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     next_action: string,
     *     close_ready: bool,
     *     source_of_truth: array{code: string, label: string, detail: string},
     *     replacement_readiness: array{status: string, status_label: string, summary: string},
     *     period_close: array<string, mixed>,
     *     ledger_summary: array<string, mixed>,
     *     metrics: array<string, int|float|bool|null|string>,
     *     checks: array<int, array{code: string, label: string, status: string, status_label: string, detail: string, blocks_close: bool}>,
     *     questions: array<int, string>,
     *     documents: array<int, string>,
     *     action: array{label: string, href: string, method?: string|null, data?: array<string, mixed>|null}|null,
     * }
     */
    public function summarize(int $userId, int $year): array
    {
        $activeBankAccounts = PersonalAccount::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
        $accountScope = $this->bookkeepingAccountScopeService->resolve($activeBankAccounts);
        $businessBankAccounts = $activeBankAccounts->where('is_business', true)->values();
        $scopedBankAccounts = $accountScope['accounts'];
        $activeQboConnections = QuickBooksConnection::active()
            ->where('user_id', $userId)
            ->get();

        $bankAccountIds = $scopedBankAccounts->modelKeys();
        $bankTransactions = $bankAccountIds === []
            ? collect()
            : PersonalTransaction::query()
                ->with(['account:id,account_type,name'])
                ->whereIn('personal_account_id', $bankAccountIds)
                ->whereYear('transaction_date', $year)
                ->get([
                    'id',
                    'personal_account_id',
                    'transaction_date',
                    'category_id',
                    'amount',
                    'description',
                    'merchant_name',
                    'is_business_expense',
                ]);

        // Cross-account business expenses: transactions on non-scoped accounts flagged as business
        $nonScopedAccountIds = $activeBankAccounts->pluck('id')->diff($bankAccountIds)->values()->all();
        $crossAccountBusinessExpenses = $nonScopedAccountIds === []
            ? collect()
            : PersonalTransaction::query()
                ->with(['account:id,account_type,name'])
                ->whereIn('personal_account_id', $nonScopedAccountIds)
                ->whereYear('transaction_date', $year)
                ->where('is_business_expense', true)
                ->get([
                    'id',
                    'personal_account_id',
                    'transaction_date',
                    'category_id',
                    'amount',
                    'description',
                    'merchant_name',
                    'is_business_expense',
                ]);
        $categoryDetails = TransactionCategory::query()
            ->whereIn('id', $bankTransactions->pluck('category_id')->filter()->unique()->values())
            ->get(['id', 'type', 'name', 'tax_category'])
            ->mapWithKeys(fn (TransactionCategory $category): array => [
                $category->id => [
                    'type' => (string) $category->type,
                    'name' => (string) $category->name,
                    'tax_category' => $category->tax_category ? (string) $category->tax_category : null,
                ],
            ]);
        $ledgerSummary = $this->ledgerSummary(
            $year,
            $activeQboConnections,
            $scopedBankAccounts,
            $bankTransactions,
            $categoryDetails,
            $crossAccountBusinessExpenses,
        );

        $inflows = $bankTransactions
            ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction))
            ->values();
        $outflows = $bankTransactions
            ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction))
            ->values();
        $uncategorizedInflows = $inflows
            ->filter(fn (PersonalTransaction $transaction): bool => $transaction->category_id === null)
            ->values();
        $uncategorizedOutflows = $outflows
            ->filter(fn (PersonalTransaction $transaction): bool => $transaction->category_id === null)
            ->values();
        $latestBankSyncAt = $scopedBankAccounts
            ->filter(fn (PersonalAccount $account): bool => $account->last_synced_at !== null)
            ->max('last_synced_at');
        $latestQboSyncAt = $activeQboConnections
            ->filter(fn (QuickBooksConnection $connection): bool => $connection->last_synced_at !== null)
            ->max('last_synced_at');
        $quickBooksSyncState = $this->quickBooksSyncState($activeQboConnections);
        $quickBooksSyncInProgress = in_array($quickBooksSyncState['code'], ['pending', 'syncing'], true);
        $bankFeedsFresh = $latestBankSyncAt instanceof Carbon && $latestBankSyncAt->gte(now()->subDays(7));
        $quickBooksFresh = $latestQboSyncAt instanceof Carbon && $latestQboSyncAt->gte(now()->subDays(7));
        $closableMonthCount = $this->closableMonthCount($year);
        $statementCoverage = $this->bookkeepingEvidenceService->statementCoverage($userId, $year, $closableMonthCount);
        $statementReconciliation = $this->statementReconciliationService->summarize(
            userId: $userId,
            year: $year,
            scopedAccounts: $scopedBankAccounts,
            bankTransactions: $bankTransactions,
            closableMonthCount: $closableMonthCount,
        );
        $structuralMovement = $this->bookkeepingEvidenceService->structuralMovementSummary($bankTransactions, $categoryDetails);
        $openBookkeepingSuggestions = BookkeepingAdjustment::query()
            ->where('user_id', $userId)
            ->where('tax_year', $year)
            ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
            ->count();
        $uncategorizedTransactionIds = $uncategorizedInflows
            ->pluck('id')
            ->merge($uncategorizedOutflows->pluck('id'))
            ->unique()
            ->values();
        $uncategorizedSuggestionCount = $uncategorizedTransactionIds->isEmpty()
            ? 0
            : BookkeepingAdjustment::query()
                ->where('user_id', $userId)
                ->where('tax_year', $year)
                ->where('status', BookkeepingAdjustment::STATUS_SUGGESTED)
                ->whereIn('personal_transaction_id', $uncategorizedTransactionIds)
                ->distinct()
                ->count('personal_transaction_id');
        $uncategorizedWithoutSuggestionCount = max(0, $uncategorizedTransactionIds->count() - $uncategorizedSuggestionCount);
        $statementBackupReady = (bool) ($statementCoverage['close_support_ready'] ?? false);
        $statementReconciliationIssueCount = (int) ($statementReconciliation['issue_count'] ?? 0);
        $missingExactStatementMonthCount = (int) ($statementReconciliation['missing_exact_month_count'] ?? 0);
        $internalBooksCandidate = $scopedBankAccounts->isNotEmpty()
            && $bankFeedsFresh
            && $bankTransactions->isNotEmpty()
            && $uncategorizedInflows->isEmpty()
            && $uncategorizedOutflows->isEmpty()
            && $statementReconciliationIssueCount === 0
            && (int) ($structuralMovement['structural_review_count'] ?? 0) === 0;
        $quickBooksUsable = $activeQboConnections->isNotEmpty()
            && $quickBooksFresh
            && (bool) $ledgerSummary['deposit_tie_out_passes'];
        $periodClose = $this->bookkeepingPeriodCloseService->summarize($userId, $year);
        $periodsClosed = (bool) ($periodClose['all_closable_periods_closed'] ?? false);
        $closeReady = $periodsClosed && ($quickBooksUsable || $internalBooksCandidate);
        $sourceOfTruth = $this->sourceOfTruth(
            hasQuickBooks: $activeQboConnections->isNotEmpty(),
            quickBooksUsable: $quickBooksUsable,
            internalBooksCandidate: $internalBooksCandidate,
        );

        $status = $this->status(
            hasBankFeeds: $scopedBankAccounts->isNotEmpty(),
            bankFeedsFresh: $bankFeedsFresh,
            hasQuickBooks: $activeQboConnections->isNotEmpty(),
            quickBooksFresh: $quickBooksFresh,
            quickBooksSyncInProgress: $quickBooksSyncInProgress,
            internalBooksCandidate: $internalBooksCandidate,
            uncategorizedInflowCount: $uncategorizedInflows->count(),
            uncategorizedOutflowCount: $uncategorizedOutflows->count(),
            structuralReviewCount: (int) ($structuralMovement['structural_review_count'] ?? 0),
            statementBackupReady: $statementBackupReady,
            statementReconciliationIssueCount: $statementReconciliationIssueCount,
            missingExactStatementMonthCount: $missingExactStatementMonthCount,
            tieOutPasses: (bool) $ledgerSummary['deposit_tie_out_passes'],
            periodsClosed: $periodsClosed,
            closeReady: $closeReady,
        );

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'summary' => $this->summary(
                status: $status,
                sourceOfTruthCode: (string) ($sourceOfTruth['code'] ?? 'incomplete'),
                bankScopeLabel: $this->bankScopeLabel((string) $accountScope['scope_code']),
                uncategorizedInflowCount: $uncategorizedInflows->count(),
                uncategorizedOutflowCount: $uncategorizedOutflows->count(),
                structuralReviewCount: (int) ($structuralMovement['structural_review_count'] ?? 0),
                uncategorizedSuggestionCount: $uncategorizedSuggestionCount,
                uncategorizedWithoutSuggestionCount: $uncategorizedWithoutSuggestionCount,
                periodClose: $periodClose,
                statementReconciliationIssueCount: (int) ($statementReconciliation['issue_count'] ?? 0),
            ),
            'next_action' => $this->nextAction(
                status: $status,
                sourceOfTruthCode: (string) ($sourceOfTruth['code'] ?? 'incomplete'),
                uncategorizedInflowCount: $uncategorizedInflows->count(),
                uncategorizedOutflowCount: $uncategorizedOutflows->count(),
                structuralReviewCount: (int) ($structuralMovement['structural_review_count'] ?? 0),
                structuralReviewTotal: (float) ($structuralMovement['structural_review_total'] ?? 0),
                uncategorizedTransactionAmount: $this->absoluteTransactionSum(
                    $uncategorizedInflows->pluck('amount')->merge($uncategorizedOutflows->pluck('amount'))
                ),
                uncategorizedSuggestionCount: $uncategorizedSuggestionCount,
                uncategorizedWithoutSuggestionCount: $uncategorizedWithoutSuggestionCount,
                tieOutDifference: (float) $ledgerSummary['deposit_tie_out_difference'],
                year: $year,
                periodClose: $periodClose,
                statementCoverage: $statementCoverage,
                statementReconciliation: $statementReconciliation,
            ),
            'close_ready' => $closeReady,
            'source_of_truth' => $sourceOfTruth,
            'replacement_readiness' => $this->replacementReadiness(
                hasQuickBooks: $activeQboConnections->isNotEmpty(),
                internalBooksCandidate: $internalBooksCandidate,
                statementBackupReady: $statementBackupReady,
                periodsClosed: $periodsClosed,
            ),
            'period_close' => $periodClose,
            'ledger_summary' => $ledgerSummary,
            'metrics' => [
                'scoped_bank_account_count' => $scopedBankAccounts->count(),
                'using_business_account_scope' => in_array((string) $accountScope['scope_code'], ['explicit_business', 'inferred_business'], true),
                'business_account_count' => $businessBankAccounts->count(),
                'account_scope_code' => (string) $accountScope['scope_code'],
                'quickbooks_connection_count' => $activeQboConnections->count(),
                'bank_transaction_count' => $bankTransactions->count(),
                'bank_inflow_count' => $inflows->count(),
                'bank_outflow_count' => $outflows->count(),
                'uncategorized_inflow_count' => $uncategorizedInflows->count(),
                'uncategorized_inflow_total' => round($this->absoluteTransactionSum($uncategorizedInflows->pluck('amount')), 2),
                'uncategorized_outflow_count' => $uncategorizedOutflows->count(),
                'uncategorized_outflow_total' => round($this->absoluteTransactionSum($uncategorizedOutflows->pluck('amount')), 2),
                'statement_document_count' => (int) ($statementCoverage['document_count'] ?? 0),
                'statement_month_coverage_count' => count($statementCoverage['covered_months'] ?? []),
                'statement_missing_month_count' => count($statementCoverage['missing_closable_months'] ?? []),
                'exact_statement_month_count' => (int) ($statementReconciliation['exact_statement_month_count'] ?? 0),
                'reconciled_statement_month_count' => (int) ($statementReconciliation['reconciled_month_count'] ?? 0),
                'statement_reconciliation_issue_count' => (int) ($statementReconciliation['issue_count'] ?? 0),
                'statement_unmatched_count' => (int) ($statementReconciliation['unmatched_statement_count'] ?? 0),
                'closable_period_count' => (int) ($periodClose['closable_month_count'] ?? 0),
                'closed_period_count' => (int) ($periodClose['closed_month_count'] ?? 0),
                'transfer_count' => (int) ($structuralMovement['transfer_count'] ?? 0),
                'business_personal_count' => (int) ($structuralMovement['business_personal_count'] ?? 0),
                'owner_equity_count' => (int) ($structuralMovement['owner_equity_count'] ?? 0),
                'loan_activity_count' => (int) ($structuralMovement['loan_activity_count'] ?? 0),
                'reimbursement_count' => (int) ($structuralMovement['reimbursement_count'] ?? 0),
                'debt_payment_count' => (int) ($structuralMovement['debt_payment_count'] ?? 0),
                'tax_payment_count' => (int) ($structuralMovement['tax_payment_count'] ?? 0),
                'structural_review_count' => (int) ($structuralMovement['structural_review_count'] ?? 0),
                'structural_review_total' => (float) ($structuralMovement['structural_review_total'] ?? 0),
                'owner_equity_review_count' => (int) ($structuralMovement['owner_equity_review_count'] ?? 0),
                'loan_activity_review_count' => (int) ($structuralMovement['loan_activity_review_count'] ?? 0),
                'reimbursement_review_count' => (int) ($structuralMovement['reimbursement_review_count'] ?? 0),
                'bookkeeping_adjustment_suggestion_count' => $openBookkeepingSuggestions,
                'uncategorized_suggestion_count' => $uncategorizedSuggestionCount,
                'uncategorized_without_suggestion_count' => $uncategorizedWithoutSuggestionCount,
                'latest_bank_sync_at' => $latestBankSyncAt?->toDateTimeString(),
                'latest_quickbooks_sync_at' => $latestQboSyncAt?->toDateTimeString(),
            ],
            'checks' => [
                $this->check(
                    code: 'bank_feeds',
                    label: 'Business bank feeds',
                    status: ! $scopedBankAccounts->isNotEmpty()
                        ? 'missing'
                        : ($bankFeedsFresh ? 'passed' : 'needs_reconnect'),
                    statusLabel: ! $scopedBankAccounts->isNotEmpty()
                        ? 'Missing'
                        : ($bankFeedsFresh ? 'Fresh' : 'Reconnect needed'),
                    detail: $this->bankScopeDetail((string) $accountScope['scope_code']),
                    blocksClose: ! $scopedBankAccounts->isNotEmpty() || ! $bankFeedsFresh,
                ),
                $this->check(
                    code: 'income_classification',
                    label: 'Business inflow classification',
                    status: $uncategorizedInflows->isEmpty() ? 'passed' : 'needs_review',
                    statusLabel: $uncategorizedInflows->isEmpty() ? 'Classified' : 'Needs categorization',
                    detail: $uncategorizedInflows->isEmpty()
                        ? 'Business inflows are classified strongly enough to separate revenue from transfers and owner contributions.'
                        : "{$uncategorizedInflows->count()} business inflow(s) still need categories.",
                    blocksClose: $activeQboConnections->isEmpty() && $uncategorizedInflows->isNotEmpty(),
                ),
                $this->check(
                    code: 'transaction_classification',
                    label: 'Business transaction classification',
                    status: $uncategorizedOutflows->isEmpty() ? 'passed' : 'needs_review',
                    statusLabel: $uncategorizedOutflows->isEmpty() ? 'Categorized' : 'Needs categorization',
                    detail: $uncategorizedOutflows->isEmpty()
                        ? 'Business outflows are categorized well enough to reason about the books.'
                        : "{$uncategorizedOutflows->count()} business outflow(s) still need categories.",
                    blocksClose: $activeQboConnections->isEmpty() && $uncategorizedOutflows->isNotEmpty(),
                ),
                $this->check(
                    code: 'quickbooks_sync',
                    label: 'QuickBooks books',
                    status: ! $activeQboConnections->isNotEmpty()
                        ? 'not_used'
                        : ($quickBooksUsable ? 'passed' : ($internalBooksCandidate ? 'not_used' : ($quickBooksSyncInProgress ? $quickBooksSyncState['code'] : ($quickBooksFresh ? 'needs_review' : 'needs_reconnect')))),
                    statusLabel: ! $activeQboConnections->isNotEmpty()
                        ? 'Not primary'
                        : ($quickBooksUsable ? 'Fresh' : ($internalBooksCandidate ? 'Optional' : ($quickBooksSyncInProgress ? $quickBooksSyncState['label'] : ($quickBooksFresh ? 'Needs review' : 'Refresh needed')))),
                    detail: ! $activeQboConnections->isNotEmpty()
                        ? 'Internal cash books are the active bookkeeping source right now.'
                        : ($internalBooksCandidate
                            ? 'QuickBooks is still connected, but the internal cash-basis close is already strong enough to carry current tax work without depending on it.'
                            : ($quickBooksSyncInProgress
                                ? (string) $quickBooksSyncState['detail']
                                : 'QuickBooks is still connected, but the real target here is cash-basis close confidence, not full accounting-system parity.')),
                    blocksClose: $activeQboConnections->isNotEmpty()
                        && ! $quickBooksFresh
                        && ! $internalBooksCandidate
                        && ! $quickBooksSyncInProgress
                        && (int) ($statementReconciliation['missing_exact_month_count'] ?? 0) === 0
                        && (int) ($statementReconciliation['issue_count'] ?? 0) === 0,
                ),
                $this->check(
                    code: 'books_to_bank_tie_out',
                    label: 'Books-to-bank tie-out',
                    status: ! $activeQboConnections->isNotEmpty()
                        ? 'not_used'
                        : ($quickBooksUsable ? 'passed' : ($internalBooksCandidate ? 'not_used' : ($ledgerSummary['deposit_tie_out_passes'] ? 'passed' : 'needs_review'))),
                    statusLabel: ! $activeQboConnections->isNotEmpty()
                        ? 'Internal mode'
                        : ($quickBooksUsable ? 'Within tolerance' : ($internalBooksCandidate ? 'Optional' : ($ledgerSummary['deposit_tie_out_passes'] ? 'Within tolerance' : 'Needs review'))),
                    detail: ! $activeQboConnections->isNotEmpty()
                        ? 'No QuickBooks ledger is active, so the close depends on internal bank-ledger coherence instead.'
                        : ($internalBooksCandidate
                            ? 'QuickBooks tie-out is now secondary because the internal cash books and exact monthly statements are already coherent.'
                            : 'QuickBooks income is reconciled against bank inflows before the packet is trusted.'),
                    blocksClose: $activeQboConnections->isNotEmpty() && ! $ledgerSummary['deposit_tie_out_passes'] && ! $internalBooksCandidate,
                ),
                $this->check(
                    code: 'statement_backup',
                    label: 'Statement support',
                    status: $statementBackupReady
                        ? 'passed'
                        : ($scopedBankAccounts->isNotEmpty() && $bankFeedsFresh ? 'not_used' : 'needs_documents'),
                    statusLabel: $statementBackupReady
                        ? 'Covered'
                        : ($scopedBankAccounts->isNotEmpty() && $bankFeedsFresh ? 'Optional' : 'Missing months'),
                    detail: $statementBackupReady
                        ? $this->statementSupportDetail($statementCoverage, $activeQboConnections->isNotEmpty())
                        : ($scopedBankAccounts->isNotEmpty() && $bankFeedsFresh
                            ? 'Live bank feeds are the primary bookkeeping source right now. Statements are helpful corroboration, not a filing blocker by themselves.'
                            : $this->statementSupportDetail($statementCoverage, $activeQboConnections->isNotEmpty())),
                    blocksClose: false,
                ),
                $this->check(
                    code: 'statement_reconciliation',
                    label: 'Statement reconciliation',
                    status: $missingExactStatementMonthCount > 0
                        && $statementReconciliationIssueCount === 0
                        && $scopedBankAccounts->isNotEmpty()
                        && $bankFeedsFresh
                        ? 'not_used'
                        : (string) ($statementReconciliation['status'] ?? 'not_available'),
                    statusLabel: $missingExactStatementMonthCount > 0
                        && $statementReconciliationIssueCount === 0
                        && $scopedBankAccounts->isNotEmpty()
                        && $bankFeedsFresh
                        ? 'Optional'
                        : (string) ($statementReconciliation['status_label'] ?? 'Not available'),
                    detail: $missingExactStatementMonthCount > 0
                        && $statementReconciliationIssueCount === 0
                        && $scopedBankAccounts->isNotEmpty()
                        && $bankFeedsFresh
                        ? 'No exact monthly statements are on file yet, but the live bank ledger is broad and current enough to keep the cash-basis close moving.'
                        : (string) ($statementReconciliation['summary'] ?? 'Monthly statements are not detailed enough yet to reconcile the ledger.'),
                    blocksClose: $statementReconciliationIssueCount > 0,
                ),
                $this->check(
                    code: 'structural_money_movement',
                    label: 'Transfer and owner movement',
                    status: (int) ($structuralMovement['structural_review_count'] ?? 0) === 0 ? 'passed' : 'needs_review',
                    statusLabel: (int) ($structuralMovement['structural_review_count'] ?? 0) === 0 ? 'Classified' : 'Needs reclass',
                    detail: $this->structuralMovementDetail($structuralMovement),
                    blocksClose: $activeQboConnections->isEmpty() && (int) ($structuralMovement['structural_review_count'] ?? 0) > 0,
                ),
                $this->check(
                    code: 'period_close',
                    label: 'Period close discipline',
                    status: $periodsClosed
                        ? 'passed'
                        : (($periodClose['all_open_periods_can_close'] ?? false) ? 'needs_close' : 'needs_review'),
                    statusLabel: $periodsClosed
                        ? 'Closed'
                        : (($periodClose['all_open_periods_can_close'] ?? false) ? 'Ready to close' : 'Blocked'),
                    detail: $this->periodCloseDetail($periodClose),
                    blocksClose: ! $periodsClosed,
                ),
            ],
            'questions' => $this->questions(
                status: $status,
                hasQuickBooks: $activeQboConnections->isNotEmpty(),
                bankFeedsFresh: $bankFeedsFresh,
                quickBooksFresh: $quickBooksFresh,
                quickBooksSyncInProgress: $quickBooksSyncInProgress,
                internalBooksCandidate: $internalBooksCandidate,
                uncategorizedInflowCount: $uncategorizedInflows->count(),
                uncategorizedOutflowCount: $uncategorizedOutflows->count(),
                structuralMovement: $structuralMovement,
                bookkeepingAdjustmentSuggestionCount: $openBookkeepingSuggestions,
                uncategorizedSuggestionCount: $uncategorizedSuggestionCount,
                uncategorizedWithoutSuggestionCount: $uncategorizedWithoutSuggestionCount,
                missingExactStatementMonthCount: (int) ($statementReconciliation['missing_exact_month_count'] ?? 0),
                year: $year,
                periodClose: $periodClose,
                statementReconciliation: $statementReconciliation,
            ),
            'documents' => $status === 'needs_documents'
                ? [$this->statementSupportRequest($year, $statementCoverage)]
                : [],
            'action' => $this->action(
                status: $status,
                year: $year,
                uncategorizedInflowCount: $uncategorizedInflows->count(),
                uncategorizedOutflowCount: $uncategorizedOutflows->count(),
                structuralReviewCount: (int) ($structuralMovement['structural_review_count'] ?? 0),
                bookkeepingAdjustmentSuggestionCount: $openBookkeepingSuggestions,
                uncategorizedSuggestionCount: $uncategorizedSuggestionCount,
                uncategorizedWithoutSuggestionCount: $uncategorizedWithoutSuggestionCount,
                periodClose: $periodClose,
                statementCoverage: $statementCoverage,
            ),
        ];
    }

    /**
     * @param  Collection<int, QuickBooksConnection>  $activeQboConnections
     * @param  Collection<int, PersonalAccount>  $scopedBankAccounts
     * @param  Collection<int, PersonalTransaction>  $bankTransactions
     * @param  Collection<int, array{name: string, type: string, tax_category: string|null}>  $categoryDetails
     * @return array<string, mixed>
     */
    protected function ledgerSummary(
        int $year,
        Collection $activeQboConnections,
        Collection $scopedBankAccounts,
        Collection $bankTransactions,
        Collection $categoryDetails,
        ?Collection $crossAccountBusinessExpenses = null,
    ): array {
        $qboConnectionIds = $activeQboConnections->pluck('id');

        $bookIncome = $qboConnectionIds->isEmpty() ? 0.0 : (float) QboTransaction::query()
            ->whereIn('qbo_connection_id', $qboConnectionIds)
            ->income()
            ->whereYear('txn_date', $year)
            ->sum('amount');
        $bookExpenses = $qboConnectionIds->isEmpty() ? 0.0 : $this->absoluteTransactionSum(
            QboTransaction::query()
                ->whereIn('qbo_connection_id', $qboConnectionIds)
                ->expenses()
                ->whereYear('txn_date', $year)
                ->pluck('amount')
        );
        $bookTransactionCount = $qboConnectionIds->isEmpty() ? 0 : QboTransaction::query()
            ->whereIn('qbo_connection_id', $qboConnectionIds)
            ->whereYear('txn_date', $year)
            ->count();
        $reconciledTransactionCount = $qboConnectionIds->isEmpty() ? 0 : QboTransaction::query()
            ->whereIn('qbo_connection_id', $qboConnectionIds)
            ->whereYear('txn_date', $year)
            ->where('is_reconciled', true)
            ->count();
        $internalBookIncome = $this->absoluteTransactionSum(
            $bankTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction))
                ->filter(fn (PersonalTransaction $transaction): bool => data_get($categoryDetails->get($transaction->category_id), 'type') === 'income')
                ->pluck('amount')
        );
        $internalBookExpenses = $this->absoluteTransactionSum(
            $bankTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction))
                ->filter(fn (PersonalTransaction $transaction): bool => data_get($categoryDetails->get($transaction->category_id), 'type') === 'expense')
                ->pluck('amount')
        );

        // Add cross-account business expenses (from non-scoped accounts flagged is_business_expense)
        $crossAccountBusinessExpenseTotal = 0.0;
        $crossAccountBusinessExpenseCount = 0;

        if ($crossAccountBusinessExpenses !== null && $crossAccountBusinessExpenses->isNotEmpty()) {
            $crossAccountBusinessExpenseTotal = $this->absoluteTransactionSum(
                $crossAccountBusinessExpenses
                    ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction))
                    ->pluck('amount')
            );
            $crossAccountBusinessExpenseCount = $crossAccountBusinessExpenses->count();
            $internalBookExpenses += $crossAccountBusinessExpenseTotal;
        }

        $internalBookTransactionCount = $bankTransactions
            ->filter(fn (PersonalTransaction $transaction): bool => in_array(data_get($categoryDetails->get($transaction->category_id), 'type'), ['income', 'expense'], true))
            ->count() + $crossAccountBusinessExpenseCount;

        $bankInflows = $scopedBankAccounts->isEmpty() ? 0.0 : $this->absoluteTransactionSum(
            $bankTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction))
                ->pluck('amount')
        );
        $bankOutflows = $scopedBankAccounts->isEmpty() ? 0.0 : $this->absoluteTransactionSum(
            $bankTransactions
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction))
                ->pluck('amount')
        );
        $usingQuickBooksBooks = $qboConnectionIds->isNotEmpty();
        $effectiveBookIncome = $usingQuickBooksBooks ? $bookIncome : $internalBookIncome;
        $effectiveBookExpenses = $usingQuickBooksBooks ? $bookExpenses : $internalBookExpenses;
        $effectiveBookTransactionCount = $usingQuickBooksBooks ? $bookTransactionCount : $internalBookTransactionCount;
        $effectiveReconciledTransactionCount = $usingQuickBooksBooks ? $reconciledTransactionCount : $internalBookTransactionCount;
        $depositTieOutDifference = abs($effectiveBookIncome - $bankInflows);
        $depositTieOutTolerance = max(250.0, abs($effectiveBookIncome) * 0.02);
        $hasTieOutData = $effectiveBookIncome > 0 && $bankInflows > 0;

        return [
            'has_books' => $usingQuickBooksBooks || $internalBookTransactionCount > 0,
            'has_bank_feed' => $scopedBankAccounts->isNotEmpty(),
            'book_source_code' => $usingQuickBooksBooks
                ? 'quickbooks'
                : ($internalBookTransactionCount > 0 ? 'internal_cash_books' : 'none'),
            'book_source_label' => $usingQuickBooksBooks
                ? 'QuickBooks books'
                : ($internalBookTransactionCount > 0 ? 'Internal cash books' : 'No books available'),
            'book_income' => round($effectiveBookIncome, 2),
            'book_expenses' => round($effectiveBookExpenses, 2),
            'book_transaction_count' => $effectiveBookTransactionCount,
            'reconciled_transaction_count' => $effectiveReconciledTransactionCount,
            'internal_book_income' => round($internalBookIncome, 2),
            'internal_book_expenses' => round($internalBookExpenses, 2),
            'cross_account_business_expenses' => round($crossAccountBusinessExpenseTotal, 2),
            'cross_account_business_expense_count' => $crossAccountBusinessExpenseCount,
            'internal_book_transaction_count' => $internalBookTransactionCount,
            'bank_deposits' => round($bankInflows, 2),
            'bank_outflows' => round($bankOutflows, 2),
            'deposit_tie_out_difference' => round($depositTieOutDifference, 2),
            'deposit_tie_out_tolerance' => round($depositTieOutTolerance, 2),
            'deposit_tie_out_passes' => $hasTieOutData && $depositTieOutDifference <= $depositTieOutTolerance,
            'has_tie_out_data' => $hasTieOutData,
            'reconciled_ratio' => $effectiveBookTransactionCount > 0
                ? round(($effectiveReconciledTransactionCount / $effectiveBookTransactionCount) * 100, 1)
                : null,
        ];
    }

    protected function status(
        bool $hasBankFeeds,
        bool $bankFeedsFresh,
        bool $hasQuickBooks,
        bool $quickBooksFresh,
        bool $quickBooksSyncInProgress,
        bool $internalBooksCandidate,
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        bool $statementBackupReady,
        int $statementReconciliationIssueCount,
        int $missingExactStatementMonthCount,
        bool $tieOutPasses,
        bool $periodsClosed,
        bool $closeReady,
    ): string {
        $classificationBlockersExist = ($uncategorizedInflowCount + $uncategorizedOutflowCount + $structuralReviewCount) > 0;

        return match (true) {
            ! $hasBankFeeds && ! $hasQuickBooks => 'needs_sources',
            $hasBankFeeds && ! $bankFeedsFresh => 'needs_reconnect',
            $classificationBlockersExist || ! $periodsClosed => 'needs_close',
            $statementReconciliationIssueCount > 0 => 'needs_review',
            $closeReady => 'ready',
            $hasQuickBooks && ! $quickBooksFresh && ! $internalBooksCandidate && ! $quickBooksSyncInProgress => 'needs_reconnect',
            $hasQuickBooks && ! $tieOutPasses && ! $internalBooksCandidate => 'needs_review',
            default => 'needs_review',
        };
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Close ready',
            'needs_reconnect' => 'Reconnect sources',
            'needs_close' => 'Close books',
            'needs_documents' => 'Add backup',
            'needs_sources' => 'Connect sources',
            default => 'Needs review',
        };
    }

    protected function summary(
        string $status,
        string $sourceOfTruthCode,
        string $bankScopeLabel,
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
        array $periodClose,
        int $statementReconciliationIssueCount,
    ): string {
        return match ($status) {
            'ready' => $sourceOfTruthCode === 'quickbooks_plus_bank'
                ? "QuickBooks plus {$bankScopeLabel} are coherent enough for filing prep, and every ended month is explicitly closed."
                : "Internal books from {$bankScopeLabel} are coherent enough for cash-basis filing prep, and every ended month is explicitly closed.",
            'needs_reconnect' => 'The bookkeeping close is stale because one or more live ledgers need to be refreshed.',
            'needs_close' => $this->classificationBlockersExist(
                $uncategorizedInflowCount,
                $uncategorizedOutflowCount,
                $structuralReviewCount,
            )
                ? $this->classificationSummary(
                    $uncategorizedInflowCount,
                    $uncategorizedOutflowCount,
                    $structuralReviewCount,
                    $uncategorizedSuggestionCount,
                    $uncategorizedWithoutSuggestionCount,
                )
                : ($this->periodCloseSummary($periodClose)
                    ?? $this->classificationSummary(
                        $uncategorizedInflowCount,
                        $uncategorizedOutflowCount,
                        $structuralReviewCount,
                        $uncategorizedSuggestionCount,
                        $uncategorizedWithoutSuggestionCount,
                    )),
            'needs_documents' => 'Supporting documents are still missing for the bookkeeping close.',
            'needs_review' => $statementReconciliationIssueCount > 0
                ? 'One or more monthly statements do not reconcile against the ledger yet.'
                : 'The books need review before the tax packet should trust them.',
            'needs_sources' => 'No dependable bookkeeping source is connected yet.',
            default => 'The books need review before the tax packet should trust them.',
        };
    }

    protected function nextAction(
        string $status,
        string $sourceOfTruthCode,
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        float $structuralReviewTotal,
        float $uncategorizedTransactionAmount,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
        float $tieOutDifference,
        int $year,
        array $periodClose,
        array $statementCoverage,
        array $statementReconciliation,
    ): string {
        return match ($status) {
            'ready' => $sourceOfTruthCode === 'quickbooks_plus_bank'
                ? 'Keep QuickBooks and the business bank feeds current, then use internal bookkeeping metrics to reduce dependency on QuickBooks over time.'
                : 'Internal books are clean enough to keep moving. The next step is preserving that close discipline each month.',
            'needs_reconnect' => 'Reconnect or refresh the live bookkeeping sources before relying on them for tax prep.',
            'needs_close' => $this->classificationBlockersExist(
                $uncategorizedInflowCount,
                $uncategorizedOutflowCount,
                $structuralReviewCount,
            )
                ? $this->classificationAction(
                    $uncategorizedInflowCount,
                    $uncategorizedOutflowCount,
                    $structuralReviewCount,
                    $uncategorizedTransactionAmount,
                    $structuralReviewTotal,
                    $uncategorizedSuggestionCount,
                    $uncategorizedWithoutSuggestionCount,
                )
                : ($this->periodCloseAction($periodClose)
                    ?? $this->classificationAction(
                        $uncategorizedInflowCount,
                        $uncategorizedOutflowCount,
                        $structuralReviewCount,
                        $uncategorizedTransactionAmount,
                        $structuralReviewTotal,
                        $uncategorizedSuggestionCount,
                        $uncategorizedWithoutSuggestionCount,
                    )),
            'needs_documents' => 'Upload '.$this->statementSupportRequest($year, $statementCoverage).'.',
            'needs_sources' => 'Connect the business bank feeds and, if still in use, refresh QuickBooks.',
            'needs_review' => (string) ($statementReconciliation['next_action'] ?? 'Review the books before drafting returns.'),
            default => $sourceOfTruthCode === 'quickbooks_plus_bank'
                ? 'Review the books-to-bank delta of $'.number_format($tieOutDifference, 2).' before drafting returns.'
                : 'Review the internal books and supporting statements before drafting returns.',
        };
    }

    protected function classificationBlockersExist(
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
    ): bool {
        return $uncategorizedInflowCount > 0
            || $uncategorizedOutflowCount > 0
            || $structuralReviewCount > 0;
    }

    /**
     * @return array{code: string, label: string, detail: string}
     */
    protected function sourceOfTruth(bool $hasQuickBooks, bool $quickBooksUsable, bool $internalBooksCandidate): array
    {
        if ($hasQuickBooks && $quickBooksUsable) {
            return [
                'code' => 'quickbooks_plus_bank',
                'label' => 'QuickBooks plus bank corroboration',
                'detail' => 'QuickBooks is still available, with business bank feeds and statements acting as the forensic backstop for a cash-basis close.',
            ];
        }

        if ($internalBooksCandidate) {
            return [
                'code' => 'internal_cash_books',
                'label' => 'Internal cash books',
                'detail' => 'Bank feeds and structural cleanup are coherent enough to run the tax workflow on internal cash books, with statements available as optional corroboration.',
            ];
        }

        return [
            'code' => 'incomplete',
            'label' => 'Incomplete bookkeeping base',
            'detail' => 'The books still need either live ledger refresh, categorization, structural reclass, or statement support.',
        ];
    }

    /**
     * @return array{status: string, status_label: string, summary: string}
     */
    protected function replacementReadiness(bool $hasQuickBooks, bool $internalBooksCandidate, bool $statementBackupReady, bool $periodsClosed): array
    {
        $status = match (true) {
            ! $internalBooksCandidate => 'not_ready',
            ! $periodsClosed => 'staging',
            $hasQuickBooks => 'staging',
            default => 'candidate',
        };

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'candidate' => 'Cash-basis ready',
                'staging' => 'Close in progress',
                default => 'Needs more close control',
            },
            'summary' => match ($status) {
                'candidate' => 'Internal cash books are coherent enough for the cash-basis tax workflow without depending on every QuickBooks feature.',
                'staging' => 'The close is getting tight, but one or two cash-basis controls still need to land before QuickBooks becomes optional.',
                default => 'The cash-basis close still needs more control before internal books should stand on their own.',
            },
        ];
    }

    protected function bankScopeLabel(string $scopeCode): string
    {
        return match ($scopeCode) {
            'explicit_business', 'inferred_business' => 'business bank feeds',
            default => 'active bank feeds',
        };
    }

    protected function bankScopeDetail(string $scopeCode): string
    {
        return match ($scopeCode) {
            'explicit_business' => 'Bookkeeping is scoped to accounts explicitly marked as business.',
            'inferred_business' => 'No business account flag is set, so bookkeeping is using the accounts that look business-related based on their names.',
            default => 'No business account flag is set, so bookkeeping is falling back to all active accounts.',
        };
    }

    /**
     * @return array{code: string, label: string, status: string, status_label: string, detail: string, blocks_close: bool}
     */
    protected function check(
        string $code,
        string $label,
        string $status,
        string $statusLabel,
        string $detail,
        bool $blocksClose,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel,
            'detail' => $detail,
            'blocks_close' => $blocksClose,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function questions(
        string $status,
        bool $hasQuickBooks,
        bool $bankFeedsFresh,
        bool $quickBooksFresh,
        bool $quickBooksSyncInProgress,
        bool $internalBooksCandidate,
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        array $structuralMovement,
        int $bookkeepingAdjustmentSuggestionCount,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
        int $missingExactStatementMonthCount,
        int $year,
        array $periodClose,
        array $statementReconciliation,
    ): array {
        $questions = [];
        $structuralReviewCount = (int) ($structuralMovement['structural_review_count'] ?? 0);
        $allBlockingClassificationsAlreadySuggested = $uncategorizedWithoutSuggestionCount === 0
            && $uncategorizedSuggestionCount > 0;

        if (! $bankFeedsFresh) {
            $questions[] = "Reconnect or refresh the business bank feeds so {$year} bookkeeping is current.";
        }

        if (
            $hasQuickBooks
            && ! $quickBooksFresh
            && ! $internalBooksCandidate
            && ! $quickBooksSyncInProgress
            && $missingExactStatementMonthCount === 0
        ) {
            $questions[] = "Refresh QuickBooks so {$year} books stay usable as a corroborating ledger while the cash-basis close stays current.";
        }

        if ($uncategorizedInflowCount > 0) {
            if ($allBlockingClassificationsAlreadySuggested) {
                $questions[] = "Review {$uncategorizedSuggestionCount} AI bookkeeping suggestion(s) so the remaining inflow decisions are explicit.";
            } else {
                $questions[] = "Categorize {$uncategorizedInflowCount} business inflow(s) so revenue is separated from transfers and owner contributions.";
            }
        }

        if ($uncategorizedOutflowCount > 0) {
            if ($allBlockingClassificationsAlreadySuggested) {
                $questions[] = "Review {$uncategorizedSuggestionCount} AI bookkeeping suggestion(s) before relying on the books.";
            } else {
                $questions[] = "Categorize {$uncategorizedOutflowCount} business outflow(s) before relying on the books.";
            }
        }

        if ($structuralReviewCount > 0) {
            $questions[] = $this->structuralReviewQuestion($structuralMovement);
        }

        if ($bookkeepingAdjustmentSuggestionCount > 0 && ! $allBlockingClassificationsAlreadySuggested) {
            $questions[] = "Review {$bookkeepingAdjustmentSuggestionCount} AI bookkeeping suggestion(s) that still need approval.";
        }

        if ((int) ($statementReconciliation['issue_count'] ?? 0) > 0) {
            $questions[] = 'Resolve the monthly statement reconciliation issues before treating the books as closed.';
        }

        if ($status === 'needs_close' && ($periodClose['all_closable_periods_closed'] ?? false) === false) {
            $latestClosablePeriod = $periodClose['latest_closable_period_label'] ?? null;

            if (is_string($latestClosablePeriod) && $latestClosablePeriod !== '') {
                $questions[] = "Explicitly close every ended bookkeeping period through {$latestClosablePeriod}.";
            }
        }

        if ($status === 'needs_review' && $hasQuickBooks && ! $internalBooksCandidate) {
            $questions[] = 'Review the books-to-bank difference before drafting returns.';
        }

        return $questions;
    }

    /**
     * @return array{label: string, href: string, method?: string|null, data?: array<string, mixed>|null}|null
     */
    protected function action(
        string $status,
        int $year,
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        int $bookkeepingAdjustmentSuggestionCount,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
        array $periodClose,
        array $statementCoverage,
    ): ?array {
        if (in_array($status, ['needs_sources', 'needs_reconnect'], true)) {
            return [
                'label' => 'Open accounts',
                'href' => '/life/accounts',
            ];
        }

        if ($status === 'needs_close') {
            if (($periodClose['all_open_periods_can_close'] ?? false) && ($periodClose['latest_closable_period_label'] ?? null) !== null) {
                return [
                    'label' => 'Close ended months',
                    'href' => '/life/accounts/bookkeeping/close',
                    'method' => 'post',
                    'data' => [
                        'tax_year' => $year,
                        'period_month' => (int) ($periodClose['closable_month_count'] ?? 0),
                    ],
                ];
            }

            if ($uncategorizedSuggestionCount > 0 && $uncategorizedWithoutSuggestionCount === 0 && $structuralReviewCount === 0) {
                return [
                    'label' => "Review {$uncategorizedSuggestionCount} AI suggestion(s)",
                    'href' => '/life/accounts?year='.$year.'#bookkeeping-suggestions',
                ];
            }

            if ($bookkeepingAdjustmentSuggestionCount > 0) {
                return [
                    'label' => 'Review bookkeeping cleanup',
                    'href' => '/life/accounts?year='.$year.'#bookkeeping-suggestions',
                ];
            }

            if (($uncategorizedInflowCount + $uncategorizedOutflowCount + $structuralReviewCount) > 0) {
                return [
                    'label' => 'Run AI bookkeeping cleanup',
                    'href' => '/life/accounts/bookkeeping/cleanup',
                    'method' => 'post',
                    'data' => [
                        'tax_year' => $year,
                        'scope' => 'business',
                        'return_to' => '/life/tax-optimizer',
                    ],
                ];
            }

            return [
                'label' => 'Open accounts',
                'href' => '/life/accounts?year='.$year,
            ];
        }

        if ($status === 'needs_documents') {
            return [
                'label' => 'Upload missing bank statements',
                'href' => '/life/documents?'.http_build_query([
                    'open_upload' => '1',
                    'tax_year' => $year,
                    'scope' => 'business',
                    'document_type' => 'bank_statement',
                    'request_label' => $this->statementSupportRequest($year, $statementCoverage),
                    'return_to' => '/life/tax-optimizer',
                ]),
            ];
        }

        if ($status === 'needs_review') {
            return [
                'label' => 'Open accounts',
                'href' => '/life/accounts?year='.$year,
            ];
        }

        return null;
    }

    protected function classificationSummary(
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
    ): string {
        if (
            $structuralReviewCount === 0
            && ($uncategorizedInflowCount + $uncategorizedOutflowCount) > 0
            && $uncategorizedWithoutSuggestionCount === 0
            && $uncategorizedSuggestionCount > 0
        ) {
            return "{$uncategorizedSuggestionCount} remaining classification item(s) are already narrowed to AI suggestions that still need approval before the internal books can be trusted.";
        }

        $parts = [];

        if ($uncategorizedInflowCount > 0) {
            $parts[] = "{$uncategorizedInflowCount} business inflow(s)";
        }

        if ($uncategorizedOutflowCount > 0) {
            $parts[] = "{$uncategorizedOutflowCount} business outflow(s)";
        }

        if ($structuralReviewCount > 0) {
            $parts[] = "{$structuralReviewCount} transfer / owner movement item(s)";
        }

        return implode(' and ', $parts).' still need classification before the internal books can be trusted.';
    }

    protected function classificationAction(
        int $uncategorizedInflowCount,
        int $uncategorizedOutflowCount,
        int $structuralReviewCount,
        float $uncategorizedTransactionAmount,
        float $structuralReviewTotal,
        int $uncategorizedSuggestionCount,
        int $uncategorizedWithoutSuggestionCount,
    ): string {
        if (
            $structuralReviewCount === 0
            && ($uncategorizedInflowCount + $uncategorizedOutflowCount) > 0
            && $uncategorizedWithoutSuggestionCount === 0
            && $uncategorizedSuggestionCount > 0
        ) {
            return "Review {$uncategorizedSuggestionCount} AI bookkeeping suggestion(s) so the remaining bookkeeping decisions are explicit before closing the books.";
        }

        $parts = [];

        if ($uncategorizedInflowCount > 0) {
            $parts[] = "{$uncategorizedInflowCount} inflow(s)";
        }

        if ($uncategorizedOutflowCount > 0) {
            $parts[] = "{$uncategorizedOutflowCount} outflow(s)";
        }

        if ($structuralReviewCount > 0) {
            $parts[] = "{$structuralReviewCount} transfer / owner movement item(s)";
        }

        return 'Categorize or reclassify '.implode(' and ', $parts).' totaling $'.number_format($uncategorizedTransactionAmount + $structuralReviewTotal, 2).' before treating the books as closed.';
    }

    protected function periodCloseSummary(array $periodClose): ?string
    {
        if (($periodClose['all_closable_periods_closed'] ?? false) === true) {
            return null;
        }

        $closedMonthCount = (int) ($periodClose['closed_month_count'] ?? 0);
        $closableMonthCount = (int) ($periodClose['closable_month_count'] ?? 0);
        $latestClosablePeriod = $periodClose['latest_closable_period_label'] ?? null;

        if (is_string($latestClosablePeriod) && $latestClosablePeriod !== '') {
            return "{$closedMonthCount} of {$closableMonthCount} ended month(s) are explicitly closed. Close the remaining months through {$latestClosablePeriod}.";
        }

        return null;
    }

    protected function periodCloseAction(array $periodClose): ?string
    {
        if (($periodClose['all_closable_periods_closed'] ?? false) === true) {
            return null;
        }

        $latestClosablePeriod = $periodClose['latest_closable_period_label'] ?? null;

        if (is_string($latestClosablePeriod) && $latestClosablePeriod !== '') {
            return "Close the bookkeeping periods through {$latestClosablePeriod} so the books are explicitly locked before filing.";
        }

        return null;
    }

    protected function periodCloseDetail(array $periodClose): string
    {
        if (($periodClose['all_closable_periods_closed'] ?? false) === true) {
            $latestClosedPeriod = $periodClose['latest_closed_period_label'] ?? null;

            return is_string($latestClosedPeriod) && $latestClosedPeriod !== ''
                ? "Every ended month is closed through {$latestClosedPeriod}."
                : 'Every ended month is closed.';
        }

        $nextOpenPeriod = $periodClose['next_open_period'] ?? null;

        if (is_array($nextOpenPeriod) && ($nextOpenPeriod['full_label'] ?? null) !== null) {
            $blockers = collect($nextOpenPeriod['blockers'] ?? [])
                ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
                ->implode(' ');

            if ($blockers !== '') {
                return "{$nextOpenPeriod['full_label']} is still open. {$blockers}";
            }

            return "{$nextOpenPeriod['full_label']} is ready to close.";
        }

        return 'Some ended months are still open.';
    }

    protected function statementSupportRequest(int $year, array $statementCoverage): string
    {
        $missingMonthLabels = $statementCoverage['missing_closable_month_labels'] ?? [];

        if ($missingMonthLabels !== []) {
            return "{$year} business bank statements covering ".implode(', ', $missingMonthLabels);
        }

        return "{$year} business bank statements to backstop the bookkeeping close";
    }

    protected function statementSupportDetail(array $statementCoverage, bool $hasQuickBooks): string
    {
        $missingMonthLabels = $statementCoverage['missing_closable_month_labels'] ?? [];
        $coveredMonthLabels = $statementCoverage['covered_month_labels'] ?? [];
        $coverageMode = (string) ($statementCoverage['coverage_mode'] ?? 'missing');

        return match (true) {
            $missingMonthLabels !== [] => 'Statement support is still missing for '.implode(', ', $missingMonthLabels).'.',
            $coverageMode === 'detailed' => 'Statement coverage is on file for '.implode(', ', $coveredMonthLabels).'.',
            $coverageMode === 'generic' => $hasQuickBooks
                ? 'Statement backup is on file, but month-level coverage has not been broken out yet.'
                : 'Generic statement backup is on file, but month-level coverage is not yet explicit.',
            default => 'Statements are still useful as corroboration and as fallback when you want to lean less on QuickBooks.',
        };
    }

    protected function structuralMovementDetail(array $structuralMovement): string
    {
        $structuralReviewCount = (int) ($structuralMovement['structural_review_count'] ?? 0);

        if ($structuralReviewCount === 0) {
            $classifiedParts = $this->structuralMovementParts($structuralMovement, false);

            if ($classifiedParts !== []) {
                return implode(', ', $classifiedParts).' are already isolated from revenue and expenses.';
            }

            $businessPersonalCount = (int) ($structuralMovement['business_personal_count'] ?? 0);

            if ($businessPersonalCount > 0) {
                return "{$businessPersonalCount} business-personal movement(s) are already isolated from revenue and expenses.";
            }

            return 'Transfers, owner movement, shareholder or owner loans, reimbursements, debt payments, and tax payments are separated cleanly enough to trust the close.';
        }

        $reviewParts = $this->structuralMovementParts($structuralMovement, true);

        if ($reviewParts !== []) {
            return implode(', ', $reviewParts).' still need the right transfer-style classification.';
        }

        return "{$structuralReviewCount} transaction(s) still look like transfers, draws, reimbursements, or owner-loan movement without the right reclass.";
    }

    /**
     * @return array<int, string>
     */
    protected function structuralMovementParts(array $structuralMovement, bool $reviewMode): array
    {
        $suffix = $reviewMode ? '_review_count' : '_count';
        $parts = [];

        $ownerEquityCount = (int) ($structuralMovement["owner_equity{$suffix}"] ?? 0);
        $loanActivityCount = (int) ($structuralMovement["loan_activity{$suffix}"] ?? 0);
        $reimbursementCount = (int) ($structuralMovement["reimbursement{$suffix}"] ?? 0);
        $genericTransferCount = (int) ($structuralMovement['transfer_review_count'] ?? 0);

        if ($ownerEquityCount > 0) {
            $parts[] = "{$ownerEquityCount} owner equity or distribution item(s)";
        }

        if ($loanActivityCount > 0) {
            $parts[] = "{$loanActivityCount} shareholder or owner loan item(s)";
        }

        if ($reimbursementCount > 0) {
            $parts[] = "{$reimbursementCount} reimbursement item(s)";
        }

        if ($reviewMode && $genericTransferCount > 0) {
            $parts[] = "{$genericTransferCount} inter-account transfer item(s)";
        }

        return $parts;
    }

    protected function structuralReviewQuestion(array $structuralMovement): string
    {
        $reviewParts = $this->structuralMovementParts($structuralMovement, true);

        if ($reviewParts === []) {
            $structuralReviewCount = (int) ($structuralMovement['structural_review_count'] ?? 0);

            return "Review {$structuralReviewCount} structural bookkeeping item(s) so transfers and owner movement do not leak into revenue or expenses.";
        }

        return 'Review '.implode(', ', $reviewParts).' so transfers, loans, reimbursements, and owner movement do not leak into revenue or expenses.';
    }

    /**
     * @param  Collection<int, QuickBooksConnection>  $connections
     * @return array{code: string, label: string, detail: string}
     */
    protected function quickBooksSyncState(Collection $connections): array
    {
        $syncingConnection = $connections->first(
            fn (QuickBooksConnection $connection): bool => $connection->sync_status === 'syncing'
        );

        if ($syncingConnection instanceof QuickBooksConnection) {
            return [
                'code' => 'syncing',
                'label' => 'Syncing now',
                'detail' => 'QuickBooks is actively syncing. Let that finish before treating the books as stale.',
            ];
        }

        $pendingConnection = $connections->first(
            fn (QuickBooksConnection $connection): bool => $connection->sync_status === 'pending'
                && $connection->updated_at?->gte(now()->subHours(6))
        );

        if ($pendingConnection instanceof QuickBooksConnection) {
            return [
                'code' => 'pending',
                'label' => 'Waiting to sync',
                'detail' => 'QuickBooks was just reconnected or queued for sync. Give the initial ledger pull a moment before treating it as stale.',
            ];
        }

        return [
            'code' => 'idle',
            'label' => 'Idle',
            'detail' => 'QuickBooks is not currently syncing.',
        ];
    }

    protected function closableMonthCount(int $year): int
    {
        $today = now();

        if ($year < $today->year) {
            return 12;
        }

        if ($year > $today->year) {
            return 0;
        }

        return max($today->month - 1, 0);
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
