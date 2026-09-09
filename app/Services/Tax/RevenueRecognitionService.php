<?php

namespace App\Services\Tax;

use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Services\PersonalFinance\BookkeepingAccountScopeService;
use App\Services\PersonalFinance\BookkeepingEvidenceService;
use App\Services\PersonalFinance\TransactionDirectionService;
use Illuminate\Support\Collection;

class RevenueRecognitionService
{
    public const REVIEW_STATUS_CONFIRMED_REVENUE = 'confirmed_revenue';

    public function __construct(
        protected BookkeepingAccountScopeService $bookkeepingAccountScopeService,
        protected BookkeepingEvidenceService $bookkeepingEvidenceService,
        protected TransactionDirectionService $transactionDirectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $annualProjection
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @return array{
     *     status: string,
     *     status_label: string,
     *     summary: string,
     *     next_action: string,
     *     basis: array{
     *         scope_code: string,
     *         scope_label: string,
     *         projection_basis_label: string,
     *         projection_basis_detail: string,
     *         recognized_basis_label: string,
     *         recognized_basis_detail: string,
     *     },
     *     metrics: array{
     *         gross_inflow_count: int,
     *         gross_inflow_total: float,
     *         recognized_revenue_count: int,
     *         recognized_revenue_total: float,
     *         excluded_inflow_count: int,
     *         excluded_inflow_total: float,
     *         unresolved_inflow_count: int,
     *         unresolved_inflow_total: float,
     *         book_recognized_income: float,
     *         projection_ytd_revenue: float,
     *         projected_annual_revenue: float,
     *         recognized_vs_projection_difference: float,
     *     },
     *     excluded_buckets: array<int, array{code: string, label: string, count: int, amount: float}>,
     *     groups: array<int, array{
     *         code: string,
     *         label: string,
     *         count: int,
     *         amount: float,
     *         detail: string,
     *         items: array<int, array{
     *             id: int,
     *             date: string,
     *             date_iso: string|null,
     *             description: string,
     *             account_label: string,
     *             amount: float,
     *             category_name: string|null,
     *             review_href: string,
     *             bucket_code: string,
     *             bucket_label: string,
     *             rationale: string,
     *         }>
     *     }>,
     *     action: array{label: string, href: string}|null,
     * }
     */
    public function summarize(int $userId, int $year, array $annualProjection = [], array $bookkeepingReadiness = []): array
    {
        $activeAccounts = PersonalAccount::query()
            ->where('user_id', $userId)
            ->active()
            ->get();
        $accountScope = $this->bookkeepingAccountScopeService->resolve($activeAccounts);
        $scopedAccounts = $accountScope['accounts'];
        $accountIds = $scopedAccounts->modelKeys();

        $inflows = $accountIds === []
            ? collect()
            : PersonalTransaction::query()
                ->with([
                    'account:id,name,institution_name,account_type',
                    'category:id,name,type,tax_category',
                ])
                ->whereIn('personal_account_id', $accountIds)
                ->whereYear('transaction_date', $year)
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get([
                    'id',
                    'personal_account_id',
                    'transaction_date',
                    'amount',
                    'description',
                    'merchant_name',
                    'category_id',
                    'revenue_recognition_review_status',
                    'revenue_recognition_reviewed_at',
                ])
                ->filter(fn (PersonalTransaction $transaction): bool => $this->transactionDirectionService->isInflow($transaction))
                ->values();

        /** @var Collection<int, array{id: int, date: string, date_iso: string|null, description: string, account_label: string, amount: float, category_name: string|null, bucket_code: string, bucket_label: string, rationale: string, group: string}> $auditedInflows */
        $auditedInflows = $inflows
            ->map(fn (PersonalTransaction $transaction): array => $this->auditItem($transaction))
            ->values();

        $recognizedInflows = $auditedInflows->where('group', 'recognized')->values();
        $excludedInflows = $auditedInflows->where('group', 'excluded')->values();
        $unresolvedInflows = $auditedInflows->where('group', 'unresolved')->values();

        $projectionYtdRevenue = round((float) ($annualProjection['ytd_revenue'] ?? 0), 2);
        $projectedAnnualRevenue = round((float) ($annualProjection['projected_annual_revenue'] ?? 0), 2);
        $bookRecognizedIncome = round(
            (float) data_get($bookkeepingReadiness, 'ledger_summary.book_income', $recognizedInflows->sum('amount')),
            2,
        );

        $grossInflowTotal = round((float) $auditedInflows->sum('amount'), 2);
        $recognizedRevenueTotal = round((float) $recognizedInflows->sum('amount'), 2);
        $excludedInflowTotal = round((float) $excludedInflows->sum('amount'), 2);
        $unresolvedInflowTotal = round((float) $unresolvedInflows->sum('amount'), 2);
        $recognizedVsProjectionDifference = round($recognizedRevenueTotal - $projectionYtdRevenue, 2);

        $status = match (true) {
            $scopedAccounts->isEmpty() => 'needs_sources',
            $unresolvedInflows->isNotEmpty() => 'needs_review',
            $auditedInflows->isEmpty() => 'no_activity',
            default => 'clear',
        };

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'summary' => $this->summary(
                status: $status,
                grossInflowTotal: $grossInflowTotal,
                recognizedRevenueTotal: $recognizedRevenueTotal,
                excludedInflowTotal: $excludedInflowTotal,
                unresolvedInflowTotal: $unresolvedInflowTotal,
                unresolvedInflowCount: $unresolvedInflows->count(),
            ),
            'next_action' => $this->nextAction(
                status: $status,
                year: $year,
                unresolvedInflowCount: $unresolvedInflows->count(),
            ),
            'basis' => [
                'scope_code' => (string) ($accountScope['scope_code'] ?? 'none'),
                'scope_label' => $this->scopeLabel((string) ($accountScope['scope_code'] ?? 'none')),
                'projection_basis_label' => 'Tax projection basis',
                'projection_basis_detail' => 'Tax Pulse still uses paid invoices plus recurring revenue logic for YTD and projected annual revenue.',
                'recognized_basis_label' => 'Book-recognized revenue',
                'recognized_basis_detail' => 'Only business inflows categorized as income count here. Transfers, owner activity, reimbursements, loans, tax activity, and other non-income credits are excluded.',
            ],
            'metrics' => [
                'gross_inflow_count' => $auditedInflows->count(),
                'gross_inflow_total' => $grossInflowTotal,
                'recognized_revenue_count' => $recognizedInflows->count(),
                'recognized_revenue_total' => $recognizedRevenueTotal,
                'excluded_inflow_count' => $excludedInflows->count(),
                'excluded_inflow_total' => $excludedInflowTotal,
                'unresolved_inflow_count' => $unresolvedInflows->count(),
                'unresolved_inflow_total' => $unresolvedInflowTotal,
                'book_recognized_income' => $bookRecognizedIncome,
                'projection_ytd_revenue' => $projectionYtdRevenue,
                'projected_annual_revenue' => $projectedAnnualRevenue,
                'recognized_vs_projection_difference' => $recognizedVsProjectionDifference,
            ],
            'excluded_buckets' => $this->excludedBucketSummary($excludedInflows),
            'groups' => [
                $this->groupSummary(
                    code: 'recognized',
                    label: 'Recognized revenue',
                    detail: 'These inflows are categorized as business income and are being treated as recognized revenue.',
                    items: $recognizedInflows,
                ),
                $this->groupSummary(
                    code: 'excluded',
                    label: 'Excluded from revenue',
                    detail: 'These inflows are on the books, but they are being excluded from revenue because they are transfers, owner movement, reimbursements, loans, or other non-income items.',
                    items: $excludedInflows,
                ),
                $this->groupSummary(
                    code: 'unresolved',
                    label: 'Still unresolved',
                    detail: 'These inflows are not being counted as recognized revenue yet because they still need a revenue-vs-transfer decision.',
                    items: $unresolvedInflows,
                ),
            ],
            'action' => $this->action($status, $year, $unresolvedInflows->count()),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     date: string,
     *     date_iso: string|null,
     *     description: string,
     *     account_label: string,
     *     amount: float,
     *     category_name: string|null,
     *     review_href: string,
     *     bucket_code: string,
     *     bucket_label: string,
     *     rationale: string,
     *     group: string,
     * }
     */
    protected function auditItem(PersonalTransaction $transaction): array
    {
        $category = $transaction->category
            ? [
                'name' => (string) $transaction->category->name,
                'type' => (string) $transaction->category->type,
                'tax_category' => $transaction->category->tax_category ? (string) $transaction->category->tax_category : null,
            ]
            : null;
        $classification = $this->bookkeepingEvidenceService->classifyRevenueRecognition($transaction, $category);
        $categoryName = (string) ($category['name'] ?? '');

        if (($classification['group'] ?? null) === 'recognized' && $this->recognizedRevenueNeedsConfirmation($transaction, $categoryName)) {
            $classification = [
                'group' => 'unresolved',
                'bucket_code' => 'needs_source_confirmation',
                'bucket_label' => 'Needs source confirmation',
                'rationale' => 'This inflow is currently tagged as income, but the description or category looks generic enough that it should be confirmed as real revenue before filing.',
            ];
        }

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->format('M d, Y') ?? 'Unknown date',
            'date_iso' => $transaction->transaction_date?->toDateString(),
            'description' => $this->description($transaction),
            'account_label' => $this->accountLabel($transaction),
            'amount' => round(abs((float) $transaction->amount), 2),
            'category_name' => $transaction->category?->name,
            'review_href' => $this->reviewHref($transaction),
            'bucket_code' => (string) $classification['bucket_code'],
            'bucket_label' => (string) $classification['bucket_label'],
            'rationale' => (string) $classification['rationale'],
            'group' => (string) $classification['group'],
        ];
    }

    protected function recognizedRevenueNeedsConfirmation(PersonalTransaction $transaction, string $categoryName): bool
    {
        if ($transaction->revenue_recognition_review_status === self::REVIEW_STATUS_CONFIRMED_REVENUE) {
            return false;
        }

        $text = strtolower(trim(implode(' ', array_filter([
            $transaction->merchant_name,
            $transaction->description,
        ]))));
        $normalizedCategory = strtolower(trim($categoryName));

        if ($this->containsAny($normalizedCategory, ['refund', 'credit', 'rebate'])) {
            return true;
        }

        return $this->containsAny($text, [
            'mobile deposit',
            'atm check deposit',
            'cash deposit',
            'check deposit',
        ]);
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'clear' => 'Revenue split is clear',
            'needs_review' => 'Needs inflow review',
            'needs_sources' => 'Connect sources',
            default => 'No inflow activity',
        };
    }

    protected function summary(
        string $status,
        float $grossInflowTotal,
        float $recognizedRevenueTotal,
        float $excludedInflowTotal,
        float $unresolvedInflowTotal,
        int $unresolvedInflowCount,
    ): string {
        return match ($status) {
            'needs_sources' => 'No business bank-ledger scope is available yet, so there is no recognized-revenue audit to review.',
            'no_activity' => 'No business inflows have been pulled into the current audit window yet.',
            'needs_review' => 'Out of '.number_format($grossInflowTotal, 2).' in gross business inflows, '
                .number_format($recognizedRevenueTotal, 2).' is recognized as revenue, '
                .number_format($excludedInflowTotal, 2).' is excluded from revenue, and '
                .number_format($unresolvedInflowTotal, 2).' across '
                .$unresolvedInflowCount.' inflow(s) still needs a revenue-vs-transfer decision.',
            default => 'Out of '.number_format($grossInflowTotal, 2).' in gross business inflows, '
                .number_format($recognizedRevenueTotal, 2).' is recognized as revenue and '
                .number_format($excludedInflowTotal, 2).' is explicitly excluded from revenue.',
        };
    }

    protected function nextAction(string $status, int $year, int $unresolvedInflowCount): string
    {
        return match ($status) {
            'needs_sources' => 'Connect or sync the business bank accounts first so revenue recognition can be audited.',
            'needs_review' => "Review the remaining {$unresolvedInflowCount} inflow(s) on the {$year} Accounts page before trusting the recognized-revenue total.",
            'no_activity' => 'Continue syncing live bank activity or upload supporting statements if the business has already started transacting.',
            default => 'Use this breakdown to verify that transfers and owner activity are staying out of recognized revenue.',
        };
    }

    protected function scopeLabel(string $scopeCode): string
    {
        return match ($scopeCode) {
            'explicit_business' => 'Explicit business accounts',
            'inferred_business' => 'Inferred business accounts',
            'all_active' => 'All active accounts',
            default => 'No bank-ledger scope',
        };
    }

    protected function description(PersonalTransaction $transaction): string
    {
        $merchant = trim((string) $transaction->merchant_name);
        $description = trim((string) $transaction->description);

        if ($merchant !== '') {
            return $merchant;
        }

        if ($description !== '') {
            return $description;
        }

        return 'Untitled inflow';
    }

    protected function accountLabel(PersonalTransaction $transaction): string
    {
        $account = $transaction->account;

        if ($account === null) {
            return 'Unknown account';
        }

        $institution = trim((string) $account->institution_name);
        $name = trim((string) $account->name);

        if ($institution !== '' && $name !== '' && $institution !== $name) {
            return "{$institution} · {$name}";
        }

        return $name !== '' ? $name : ($institution !== '' ? $institution : 'Unknown account');
    }

    /**
     * @param  Collection<int, array{id: int, date: string, date_iso: string|null, description: string, account_label: string, amount: float, category_name: string|null, bucket_code: string, bucket_label: string, rationale: string, group: string}>  $items
     * @return array<int, array{code: string, label: string, count: int, amount: float}>
     */
    protected function excludedBucketSummary(Collection $items): array
    {
        return $items
            ->groupBy('bucket_code')
            ->map(function (Collection $bucketItems, string $bucketCode): array {
                $firstItem = $bucketItems->first();

                return [
                    'code' => $bucketCode,
                    'label' => (string) ($firstItem['bucket_label'] ?? 'Excluded'),
                    'count' => $bucketItems->count(),
                    'amount' => round((float) $bucketItems->sum('amount'), 2),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{id: int, date: string, date_iso: string|null, description: string, account_label: string, amount: float, category_name: string|null, bucket_code: string, bucket_label: string, rationale: string, group: string}>  $items
     * @return array{
     *     code: string,
     *     label: string,
     *     count: int,
     *     amount: float,
     *     detail: string,
     *     items: array<int, array{
     *         id: int,
     *         date: string,
     *         date_iso: string|null,
     *         description: string,
     *         account_label: string,
     *         amount: float,
     *         category_name: string|null,
     *         bucket_code: string,
     *         bucket_label: string,
     *         rationale: string,
     *     }>,
     * }
     */
    protected function groupSummary(string $code, string $label, string $detail, Collection $items): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'count' => $items->count(),
            'amount' => round((float) $items->sum('amount'), 2),
            'detail' => $detail,
            'items' => $items
                ->take(12)
                ->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'date' => $item['date'],
                    'date_iso' => $item['date_iso'],
                    'description' => $item['description'],
                    'account_label' => $item['account_label'],
                    'amount' => $item['amount'],
                    'category_name' => $item['category_name'],
                    'review_href' => $item['review_href'],
                    'bucket_code' => $item['bucket_code'],
                    'bucket_label' => $item['bucket_label'],
                    'rationale' => $item['rationale'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{label: string, href: string}|null
     */
    protected function action(string $status, int $year, int $unresolvedInflowCount): ?array
    {
        return match ($status) {
            'needs_review' => [
                'label' => $unresolvedInflowCount === 1 ? 'Review unresolved inflow' : "Review {$unresolvedInflowCount} unresolved inflow(s)",
                'href' => "/life/tax-optimizer?year={$year}#revenue-audit",
            ],
            'needs_sources' => [
                'label' => 'Open Accounts',
                'href' => "/life/accounts?year={$year}",
            ],
            default => null,
        };
    }

    protected function reviewHref(PersonalTransaction $transaction): string
    {
        $query = [
            'start_date' => $transaction->transaction_date?->toDateString(),
            'end_date' => $transaction->transaction_date?->toDateString(),
        ];

        $search = trim((string) ($transaction->merchant_name ?: $transaction->description ?: ''));

        if ($search !== '') {
            $query['search'] = mb_substr($search, 0, 80);
        }

        return route('life.transactions', [
            'account' => $transaction->personal_account_id,
            ...$query,
        ]);
    }

    /**
     * @param  array<int, string>  $needles
     */
    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
