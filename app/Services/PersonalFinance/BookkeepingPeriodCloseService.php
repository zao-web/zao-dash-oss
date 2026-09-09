<?php

namespace App\Services\PersonalFinance;

use App\Models\BookkeepingPeriodClose;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\QuickBooksConnection;
use App\Models\TransactionCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BookkeepingPeriodCloseService
{
    public function __construct(
        protected BookkeepingEvidenceService $bookkeepingEvidenceService,
        protected StatementReconciliationService $statementReconciliationService,
        protected BookkeepingAccountScopeService $bookkeepingAccountScopeService,
        protected TransactionDirectionService $transactionDirectionService,
    ) {}

    /**
     * @return array{
     *     year: int,
     *     closable_month_count: int,
     *     closed_month_count: int,
     *     open_month_count: int,
     *     all_closable_periods_closed: bool,
     *     all_open_periods_can_close: bool,
     *     latest_closable_period_label: string|null,
     *     latest_closed_period_label: string|null,
     *     next_open_period: array<string, mixed>|null,
     *     periods: array<int, array<string, mixed>>,
     * }
     */
    public function summarize(int $userId, int $year): array
    {
        $closableMonthCount = $this->closableMonthCount($year);
        $accounts = PersonalAccount::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
        $scopedAccounts = $this->bookkeepingAccountScopeService->resolve($accounts)['accounts'];
        $accountIds = $scopedAccounts->modelKeys();
        $transactionsByMonth = $accountIds === []
            ? collect()
            : PersonalTransaction::query()
                ->with(['account:id,account_type,name'])
                ->whereIn('personal_account_id', $accountIds)
                ->whereYear('transaction_date', $year)
                ->get([
                    'id',
                    'personal_account_id',
                    'category_id',
                    'amount',
                    'description',
                    'merchant_name',
                    'transaction_date',
                ])
                ->groupBy(fn (PersonalTransaction $transaction): int => $transaction->transaction_date->month);
        $categoryDetails = TransactionCategory::query()
            ->whereIn('id', $transactionsByMonth->flatten(1)->pluck('category_id')->filter()->unique()->values())
            ->get(['id', 'type', 'name', 'tax_category'])
            ->mapWithKeys(fn (TransactionCategory $category): array => [
                $category->id => [
                    'type' => (string) $category->type,
                    'name' => (string) $category->name,
                    'tax_category' => $category->tax_category ? (string) $category->tax_category : null,
                ],
            ]);
        $latestBankSyncAt = $scopedAccounts
            ->filter(fn (PersonalAccount $account): bool => $account->last_synced_at !== null)
            ->max('last_synced_at');
        $quickBooksConnections = QuickBooksConnection::active()
            ->where('user_id', $userId)
            ->get();
        $latestQuickBooksSyncAt = $quickBooksConnections
            ->filter(fn (QuickBooksConnection $connection): bool => $connection->last_synced_at !== null)
            ->max('last_synced_at');
        $statementCoverage = $this->bookkeepingEvidenceService->statementCoverage($userId, $year, $closableMonthCount);
        $statementReconciliation = $this->statementReconciliationService->summarize(
            userId: $userId,
            year: $year,
            scopedAccounts: $scopedAccounts,
            bankTransactions: $transactionsByMonth->flatten(1),
            closableMonthCount: $closableMonthCount,
        );
        $statementReconciliationByMonth = collect($statementReconciliation['months'] ?? [])->keyBy('month');
        $coveredStatementMonths = collect($statementCoverage['covered_months'] ?? []);
        $hasDetailedStatementCoverage = (string) ($statementCoverage['coverage_mode'] ?? 'missing') === 'detailed';
        $hasGenericStatementBackup = (int) ($statementCoverage['generic_statement_count'] ?? 0) > 0;
        $existingCloses = BookkeepingPeriodClose::query()
            ->where('user_id', $userId)
            ->where('tax_year', $year)
            ->get()
            ->keyBy('period_month');
        $periods = collect(range(1, 12))
            ->map(fn (int $month): array => $this->summarizeMonth(
                year: $year,
                month: $month,
                closableMonthCount: $closableMonthCount,
                scopedAccounts: $scopedAccounts,
                quickBooksConnections: $quickBooksConnections,
                latestBankSyncAt: $latestBankSyncAt,
                latestQuickBooksSyncAt: $latestQuickBooksSyncAt,
                statementCovered: $this->monthHasStatementSupport(
                    $month,
                    $coveredStatementMonths,
                    $hasDetailedStatementCoverage,
                    $hasGenericStatementBackup,
                ),
                statementReconciliation: $statementReconciliationByMonth->get($month),
                existingClose: $existingCloses->get($month),
                transactions: collect($transactionsByMonth->get($month, [])),
                categoryDetails: $categoryDetails,
            ))
            ->all();

        $closablePeriods = collect($periods)->filter(fn (array $period): bool => (bool) $period['closable']);
        $closedPeriods = $closablePeriods->filter(fn (array $period): bool => (bool) $period['closed'])->values();
        $openPeriods = $closablePeriods->reject(fn (array $period): bool => (bool) $period['closed'])->values();
        $latestClosablePeriod = $closablePeriods->last();
        $latestClosedPeriod = $closedPeriods->last();
        $nextOpenPeriod = $openPeriods->first();

        return [
            'year' => $year,
            'closable_month_count' => $closablePeriods->count(),
            'closed_month_count' => $closedPeriods->count(),
            'open_month_count' => $openPeriods->count(),
            'all_closable_periods_closed' => $openPeriods->isEmpty(),
            'all_open_periods_can_close' => $openPeriods->isNotEmpty()
                && $openPeriods->every(fn (array $period): bool => (bool) $period['can_close']),
            'latest_closable_period_label' => is_array($latestClosablePeriod) ? $latestClosablePeriod['full_label'] : null,
            'latest_closed_period_label' => is_array($latestClosedPeriod) ? $latestClosedPeriod['full_label'] : null,
            'next_open_period' => is_array($nextOpenPeriod) ? $nextOpenPeriod : null,
            'periods' => $periods,
        ];
    }

    /**
     * @return array<int, BookkeepingPeriodClose>
     */
    public function closeThroughPeriod(int $userId, int $year, int $throughMonth, ?string $notes = null): array
    {
        $summary = $this->summarize($userId, $year);
        $closableMonthCount = (int) ($summary['closable_month_count'] ?? 0);

        if ($throughMonth < 1 || $throughMonth > 12) {
            throw ValidationException::withMessages([
                'period_month' => 'Choose a bookkeeping month between January and December.',
            ]);
        }

        if ($throughMonth > $closableMonthCount) {
            throw ValidationException::withMessages([
                'period_month' => "You can only close through {$summary['latest_closable_period_label']} right now.",
            ]);
        }

        $candidatePeriods = collect($summary['periods'] ?? [])
            ->filter(fn (array $period): bool => (bool) $period['closable'])
            ->reject(fn (array $period): bool => (bool) $period['closed'])
            ->filter(fn (array $period): bool => (int) $period['month'] <= $throughMonth)
            ->values();

        $blockedPeriods = $candidatePeriods
            ->filter(fn (array $period): bool => ! (bool) $period['can_close'])
            ->mapWithKeys(fn (array $period): array => [
                "period_{$period['month']}" => "Cannot close {$period['full_label']}: ".implode(' ', $period['blockers']),
            ])
            ->all();

        if ($blockedPeriods !== []) {
            throw ValidationException::withMessages($blockedPeriods);
        }

        $created = [];

        foreach ($candidatePeriods as $period) {
            $created[] = BookkeepingPeriodClose::updateOrCreate(
                [
                    'user_id' => $userId,
                    'tax_year' => $year,
                    'period_month' => (int) $period['month'],
                ],
                [
                    'period_key' => (string) $period['period_key'],
                    'period_start_date' => (string) $period['start_date'],
                    'period_end_date' => (string) $period['end_date'],
                    'status' => BookkeepingPeriodClose::STATUS_CLOSED,
                    'ledger_source_code' => (string) ($period['ledger_source_code'] ?? 'none'),
                    'notes' => $notes,
                    'closed_at' => now(),
                    'snapshot' => [
                        'transaction_count' => (int) ($period['transaction_count'] ?? 0),
                        'uncategorized_inflow_count' => (int) ($period['uncategorized_inflow_count'] ?? 0),
                        'uncategorized_outflow_count' => (int) ($period['uncategorized_outflow_count'] ?? 0),
                        'structural_review_count' => (int) ($period['structural_review_count'] ?? 0),
                        'statement_backup_ready' => (bool) ($period['statement_backup_ready'] ?? false),
                        'source_of_truth' => (string) ($period['source_of_truth_code'] ?? 'incomplete'),
                    ],
                ],
            );
        }

        return $created;
    }

    /**
     * @param  Collection<int, PersonalAccount>  $scopedAccounts
     * @param  Collection<int, QuickBooksConnection>  $quickBooksConnections
     * @param  Collection<int, PersonalTransaction>  $transactions
     * @param  Collection<int, array{name: string, type: string, tax_category: string|null}>  $categoryDetails
     * @return array<string, mixed>
     */
    protected function summarizeMonth(
        int $year,
        int $month,
        int $closableMonthCount,
        Collection $scopedAccounts,
        Collection $quickBooksConnections,
        mixed $latestBankSyncAt,
        mixed $latestQuickBooksSyncAt,
        bool $statementCovered,
        ?array $statementReconciliation,
        ?BookkeepingPeriodClose $existingClose,
        Collection $transactions,
        Collection $categoryDetails,
    ): array {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();
        $isClosable = $month <= $closableMonthCount;
        $uncategorizedInflows = $transactions
            ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction) && $transaction->category_id === null)
            ->count();
        $uncategorizedOutflows = $transactions
            ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isOutflow($transaction) && $transaction->category_id === null)
            ->count();
        $structuralMovement = $this->bookkeepingEvidenceService->structuralMovementSummary($transactions, $categoryDetails);
        $bankFeedsFresh = $latestBankSyncAt instanceof Carbon && $latestBankSyncAt->gte(now()->subDays(7));
        $quickBooksFresh = $latestQuickBooksSyncAt instanceof Carbon && $latestQuickBooksSyncAt->gte(now()->subDays(7));
        $quickBooksSyncInProgress = $this->quickBooksSyncInProgress($quickBooksConnections);
        $statementReconciliationStatus = (string) ($statementReconciliation['status'] ?? 'missing');
        $hasCleanupBlockers = $uncategorizedInflows > 0
            || $uncategorizedOutflows > 0
            || (int) ($structuralMovement['structural_review_count'] ?? 0) > 0;
        $statementNeedsReview = in_array($statementReconciliationStatus, ['needs_review', 'needs_match'], true);
        $internalCashMonthCandidate = $bankFeedsFresh
            && $uncategorizedInflows === 0
            && $uncategorizedOutflows === 0
            && (int) ($structuralMovement['structural_review_count'] ?? 0) === 0
            && ! $statementNeedsReview;
        $sourceOfTruthCode = $quickBooksConnections->isNotEmpty() && $quickBooksFresh
            ? 'quickbooks_plus_bank'
            : ($internalCashMonthCandidate ? 'internal_cash_books' : 'incomplete');

        $blockers = [];

        if (! $isClosable) {
            $blockers[] = 'This month is still open.';
        }

        if ($scopedAccounts->isEmpty()) {
            $blockers[] = 'No bank feeds are connected.';
        } elseif (! $bankFeedsFresh) {
            $blockers[] = 'Bank feeds need a refresh.';
        }

        if (
            $quickBooksConnections->isNotEmpty()
            && ! $quickBooksFresh
            && ! $internalCashMonthCandidate
            && ! $hasCleanupBlockers
            && ! $statementNeedsReview
            && $bankFeedsFresh
            && $statementCovered
            && $statementReconciliationStatus !== 'missing'
        ) {
            $blockers[] = $quickBooksSyncInProgress
                ? 'QuickBooks sync is still running.'
                : 'QuickBooks needs a refresh.';
        }

        if (is_array($statementReconciliation)) {
            $reconciliationStatus = (string) ($statementReconciliation['status'] ?? 'missing');

            if (in_array($reconciliationStatus, ['needs_review', 'needs_match'], true)) {
                $blockers[] = (string) ($statementReconciliation['detail'] ?? "Statement reconciliation for {$startDate->format('F Y')} still needs review.");
            }
        }

        if ($uncategorizedInflows > 0) {
            $blockers[] = "{$uncategorizedInflows} inflow(s) still need categories.";
        }

        if ($uncategorizedOutflows > 0) {
            $blockers[] = "{$uncategorizedOutflows} outflow(s) still need categories.";
        }

        if ((int) ($structuralMovement['structural_review_count'] ?? 0) > 0) {
            $blockers[] = "{$structuralMovement['structural_review_count']} transfer / owner movement item(s) still need reclass.";
        }

        return [
            'month' => $month,
            'label' => $startDate->format('M'),
            'full_label' => $startDate->format('F Y'),
            'period_key' => $startDate->format('Y-m'),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'transaction_count' => $transactions->count(),
            'uncategorized_inflow_count' => $uncategorizedInflows,
            'uncategorized_outflow_count' => $uncategorizedOutflows,
            'structural_review_count' => (int) ($structuralMovement['structural_review_count'] ?? 0),
            'statement_backup_ready' => $statementCovered,
            'statement_reconciliation_status' => $statementReconciliation['status'] ?? ($statementCovered ? 'not_available' : 'missing'),
            'statement_reconciliation_status_label' => $statementReconciliation['status_label'] ?? ($statementCovered ? 'Not available' : 'Missing exact statement'),
            'source_of_truth_code' => $sourceOfTruthCode,
            'ledger_source_code' => $quickBooksConnections->isNotEmpty() && $quickBooksFresh ? 'quickbooks' : 'internal_cash_books',
            'closable' => $isClosable,
            'closed' => $existingClose !== null,
            'closed_at' => $existingClose?->closed_at?->toDateTimeString(),
            'can_close' => $existingClose === null && $isClosable && $blockers === [],
            'blockers' => $existingClose !== null ? [] : $blockers,
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
     * @param  Collection<int, QuickBooksConnection>  $connections
     */
    protected function quickBooksSyncInProgress(Collection $connections): bool
    {
        return $connections->contains(
            fn (QuickBooksConnection $connection): bool => in_array((string) $connection->sync_status, ['pending', 'syncing'], true)
                && (
                    $connection->sync_status === 'syncing'
                    || $connection->updated_at?->gte(now()->subHours(6))
                )
        );
    }

    /**
     * @param  Collection<int, int>  $coveredStatementMonths
     */
    protected function monthHasStatementSupport(
        int $month,
        Collection $coveredStatementMonths,
        bool $hasDetailedStatementCoverage,
        bool $hasGenericStatementBackup,
    ): bool {
        if ($hasDetailedStatementCoverage) {
            return $coveredStatementMonths->contains($month);
        }

        return $hasGenericStatementBackup;
    }
}
