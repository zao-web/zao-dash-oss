<?php

namespace App\Services\PersonalFinance;

use App\Models\FinancialDocument;
use App\Models\PersonalTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BookkeepingEvidenceService
{
    /**
     * @param  array{name?: string, type?: string, tax_category?: string|null}|null  $category
     * @return array{
     *     group: string,
     *     bucket_code: string,
     *     bucket_label: string,
     *     rationale: string,
     * }
     */
    public function classifyRevenueRecognition(PersonalTransaction $transaction, ?array $category): array
    {
        $categoryType = (string) ($category['type'] ?? '');
        $categoryName = strtolower((string) ($category['name'] ?? ''));
        $text = $this->transactionText($transaction);
        $movementSubtype = $this->businessPersonalMovementSubtype($text, $categoryName);

        if ($categoryType === 'income') {
            return [
                'group' => 'recognized',
                'bucket_code' => 'recognized_revenue',
                'bucket_label' => 'Recognized revenue',
                'rationale' => 'This inflow is categorized as business income and counts toward recognized revenue.',
            ];
        }

        if ($categoryType === '') {
            if ($movementSubtype === 'owner_equity') {
                return [
                    'group' => 'unresolved',
                    'bucket_code' => 'likely_owner_equity',
                    'bucket_label' => 'Likely owner equity',
                    'rationale' => 'This inflow is still uncategorized, but the description looks like an owner contribution or distribution.',
                ];
            }

            if ($movementSubtype === 'loan_activity') {
                return [
                    'group' => 'unresolved',
                    'bucket_code' => 'likely_loan_activity',
                    'bucket_label' => 'Likely owner loan',
                    'rationale' => 'This inflow is still uncategorized, but the description looks like shareholder or owner loan activity.',
                ];
            }

            if ($movementSubtype === 'reimbursement') {
                return [
                    'group' => 'unresolved',
                    'bucket_code' => 'likely_reimbursement',
                    'bucket_label' => 'Likely reimbursement',
                    'rationale' => 'This inflow is still uncategorized, but the description looks like a reimbursement rather than revenue.',
                ];
            }

            if ($this->looksLikeTransferMovement($text)) {
                return [
                    'group' => 'unresolved',
                    'bucket_code' => 'likely_transfer',
                    'bucket_label' => 'Likely transfer',
                    'rationale' => 'This inflow is still uncategorized, but the description looks like an internal transfer between accounts.',
                ];
            }

            return [
                'group' => 'unresolved',
                'bucket_code' => 'uncategorized',
                'bucket_label' => 'Still needs review',
                'rationale' => 'This inflow is still uncategorized, so the system is not treating it as recognized revenue yet.',
            ];
        }

        if ($categoryType === 'transfer') {
            return match ($movementSubtype) {
                'owner_equity' => [
                    'group' => 'excluded',
                    'bucket_code' => 'owner_equity',
                    'bucket_label' => 'Owner equity / distributions',
                    'rationale' => 'Owner contributions, draws, and distributions are excluded from recognized revenue.',
                ],
                'loan_activity' => [
                    'group' => 'excluded',
                    'bucket_code' => 'loan_activity',
                    'bucket_label' => 'Owner or shareholder loans',
                    'rationale' => 'Loan activity is excluded from recognized revenue.',
                ],
                'reimbursement' => [
                    'group' => 'excluded',
                    'bucket_code' => 'reimbursement',
                    'bucket_label' => 'Reimbursements',
                    'rationale' => 'Reimbursements are tracked separately and excluded from recognized revenue.',
                ],
                default => [
                    'group' => 'excluded',
                    'bucket_code' => 'transfer',
                    'bucket_label' => 'Transfers between accounts',
                    'rationale' => 'Transfer-category inflows are excluded from recognized revenue.',
                ],
            };
        }

        if ($categoryType === 'expense') {
            return [
                'group' => 'excluded',
                'bucket_code' => 'expense_credit',
                'bucket_label' => 'Expense credits or refunds',
                'rationale' => 'This inflow is categorized on the expense side and is excluded from recognized revenue.',
            ];
        }

        if ($categoryType === 'debt_payment') {
            return [
                'group' => 'excluded',
                'bucket_code' => 'debt_activity',
                'bucket_label' => 'Debt activity',
                'rationale' => 'Debt-related inflows are excluded from recognized revenue.',
            ];
        }

        if ($categoryType === 'tax_payment') {
            return [
                'group' => 'excluded',
                'bucket_code' => 'tax_activity',
                'bucket_label' => 'Tax activity',
                'rationale' => 'Tax payments or tax-related credits are excluded from recognized revenue.',
            ];
        }

        return [
            'group' => 'excluded',
            'bucket_code' => 'other_non_revenue',
            'bucket_label' => 'Other non-revenue inflows',
            'rationale' => 'This inflow is not categorized as business income, so it is excluded from recognized revenue.',
        ];
    }

    /**
     * @return array{
     *     document_count: int,
     *     detailed_statement_count: int,
     *     generic_statement_count: int,
     *     coverage_mode: string,
     *     covered_months: array<int, int>,
     *     covered_month_labels: array<int, string>,
     *     missing_closable_months: array<int, int>,
     *     missing_closable_month_labels: array<int, string>,
     *     close_support_ready: bool,
     * }
     */
    public function statementCoverage(int $userId, int $year, int $closableMonthCount = 0): array
    {
        $documents = FinancialDocument::query()
            ->where('user_id', $userId)
            ->whereIn('document_type', ['bank_statement', 'credit_card_statement'])
            ->get();

        $matchingDocuments = $documents
            ->filter(fn (FinancialDocument $document): bool => $this->statementMatchesYear($document, $year))
            ->values();

        $coveredMonths = collect();
        $detailedStatementCount = 0;
        $genericStatementCount = 0;

        foreach ($matchingDocuments as $document) {
            $documentMonths = $this->statementCoveredMonths($document, $year);

            if ($documentMonths !== []) {
                $detailedStatementCount++;
                $coveredMonths = $coveredMonths->merge($documentMonths);

                continue;
            }

            $genericStatementCount++;
        }

        $coveredMonthValues = $coveredMonths
            ->filter(fn (mixed $month): bool => is_int($month) && $month >= 1 && $month <= 12)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $closableMonths = $closableMonthCount > 0 ? range(1, $closableMonthCount) : [];
        $hasDetailedCoverage = $coveredMonthValues !== [];
        $missingClosableMonths = $hasDetailedCoverage
            ? array_values(array_diff($closableMonths, $coveredMonthValues))
            : ($genericStatementCount > 0 ? [] : $closableMonths);

        return [
            'document_count' => $matchingDocuments->count(),
            'detailed_statement_count' => $detailedStatementCount,
            'generic_statement_count' => $genericStatementCount,
            'coverage_mode' => match (true) {
                $hasDetailedCoverage => 'detailed',
                $genericStatementCount > 0 => 'generic',
                default => 'missing',
            },
            'covered_months' => $coveredMonthValues,
            'covered_month_labels' => $this->monthLabels($coveredMonthValues),
            'missing_closable_months' => $missingClosableMonths,
            'missing_closable_month_labels' => $this->monthLabels($missingClosableMonths),
            'close_support_ready' => $closableMonthCount === 0
                ? $matchingDocuments->isNotEmpty()
                : $missingClosableMonths === [],
        ];
    }

    /**
     * @param  Collection<int, PersonalTransaction>  $transactions
     * @param  Collection<int, array{name: string, type: string, tax_category: string|null}>  $categories
     * @return array{
     *     transfer_count: int,
     *     transfer_total: float,
     *     business_personal_count: int,
     *     business_personal_total: float,
     *     owner_equity_count: int,
     *     owner_equity_total: float,
     *     loan_activity_count: int,
     *     loan_activity_total: float,
     *     reimbursement_count: int,
     *     reimbursement_total: float,
     *     debt_payment_count: int,
     *     debt_payment_total: float,
     *     tax_payment_count: int,
     *     tax_payment_total: float,
     *     transfer_review_count: int,
     *     transfer_review_total: float,
     *     owner_activity_review_count: int,
     *     owner_activity_review_total: float,
     *     owner_equity_review_count: int,
     *     owner_equity_review_total: float,
     *     loan_activity_review_count: int,
     *     loan_activity_review_total: float,
     *     reimbursement_review_count: int,
     *     reimbursement_review_total: float,
     *     structural_review_count: int,
     *     structural_review_total: float,
     * }
     */
    public function structuralMovementSummary(Collection $transactions, Collection $categories): array
    {
        $summary = [
            'transfer_count' => 0,
            'transfer_total' => 0.0,
            'business_personal_count' => 0,
            'business_personal_total' => 0.0,
            'owner_equity_count' => 0,
            'owner_equity_total' => 0.0,
            'loan_activity_count' => 0,
            'loan_activity_total' => 0.0,
            'reimbursement_count' => 0,
            'reimbursement_total' => 0.0,
            'debt_payment_count' => 0,
            'debt_payment_total' => 0.0,
            'tax_payment_count' => 0,
            'tax_payment_total' => 0.0,
            'transfer_review_count' => 0,
            'transfer_review_total' => 0.0,
            'owner_activity_review_count' => 0,
            'owner_activity_review_total' => 0.0,
            'owner_equity_review_count' => 0,
            'owner_equity_review_total' => 0.0,
            'loan_activity_review_count' => 0,
            'loan_activity_review_total' => 0.0,
            'reimbursement_review_count' => 0,
            'reimbursement_review_total' => 0.0,
            'structural_review_count' => 0,
            'structural_review_total' => 0.0,
        ];

        foreach ($transactions as $transaction) {
            $category = $categories->get($transaction->category_id);
            $categoryType = (string) ($category['type'] ?? '');
            $categoryName = strtolower((string) ($category['name'] ?? ''));
            $amount = abs((float) $transaction->amount);
            $text = $this->transactionText($transaction);
            $movementSubtype = $this->businessPersonalMovementSubtype($text, $categoryName);

            if ($categoryType === 'transfer') {
                $summary['transfer_count']++;
                $summary['transfer_total'] += $amount;
            }

            if ($categoryType === 'debt_payment') {
                $summary['debt_payment_count']++;
                $summary['debt_payment_total'] += $amount;
            }

            if ($categoryType === 'tax_payment') {
                $summary['tax_payment_count']++;
                $summary['tax_payment_total'] += $amount;
            }

            if ($categoryType === 'transfer' && $movementSubtype !== null) {
                $summary['business_personal_count']++;
                $summary['business_personal_total'] += $amount;

                $this->incrementMovementSubtypeCount($summary, $movementSubtype, $amount);
            }

            $reviewed = false;

            if ($movementSubtype === null && $this->looksLikeTransferMovement($text) && $categoryType !== 'transfer') {
                $summary['transfer_review_count']++;
                $summary['transfer_review_total'] += $amount;
                $summary['structural_review_count']++;
                $summary['structural_review_total'] += $amount;
                $reviewed = true;
            }

            if ($movementSubtype !== null && $categoryType !== 'transfer') {
                $summary['owner_activity_review_count']++;
                $summary['owner_activity_review_total'] += $amount;
                $this->incrementMovementSubtypeReviewCount($summary, $movementSubtype, $amount);

                if (! $reviewed) {
                    $summary['structural_review_count']++;
                    $summary['structural_review_total'] += $amount;
                }
            }
        }

        $summary['transfer_total'] = round($summary['transfer_total'], 2);
        $summary['business_personal_total'] = round($summary['business_personal_total'], 2);
        $summary['owner_equity_total'] = round($summary['owner_equity_total'], 2);
        $summary['loan_activity_total'] = round($summary['loan_activity_total'], 2);
        $summary['reimbursement_total'] = round($summary['reimbursement_total'], 2);
        $summary['debt_payment_total'] = round($summary['debt_payment_total'], 2);
        $summary['tax_payment_total'] = round($summary['tax_payment_total'], 2);
        $summary['transfer_review_total'] = round($summary['transfer_review_total'], 2);
        $summary['owner_activity_review_total'] = round($summary['owner_activity_review_total'], 2);
        $summary['owner_equity_review_total'] = round($summary['owner_equity_review_total'], 2);
        $summary['loan_activity_review_total'] = round($summary['loan_activity_review_total'], 2);
        $summary['reimbursement_review_total'] = round($summary['reimbursement_review_total'], 2);
        $summary['structural_review_total'] = round($summary['structural_review_total'], 2);

        return $summary;
    }

    protected function statementMatchesYear(FinancialDocument $document, int $year): bool
    {
        $taxYear = $this->documentTaxYear($document);

        if ($taxYear !== null) {
            return $taxYear === $year;
        }

        $startDate = $this->statementDateValue($document, 'period_start');
        $endDate = $this->statementDateValue($document, 'period_end');

        if ($startDate instanceof Carbon && $endDate instanceof Carbon) {
            return $startDate->year <= $year && $endDate->year >= $year;
        }

        if ($document->effective_date?->year === $year) {
            return true;
        }

        return str_contains($document->file_name, (string) $year);
    }

    /**
     * @return array<int, int>
     */
    protected function statementCoveredMonths(FinancialDocument $document, int $year): array
    {
        $startDate = $this->statementDateValue($document, 'period_start');
        $endDate = $this->statementDateValue($document, 'period_end');
        $taxYear = $this->documentTaxYear($document);

        if ($startDate instanceof Carbon && $endDate instanceof Carbon) {
            $current = $startDate->copy()->startOfMonth();
            $months = [];

            while ($current->lte($endDate)) {
                if ($current->year === $year) {
                    $months[] = $current->month;
                }

                $current->addMonthNoOverflow();
            }

            return array_values(array_unique($months));
        }

        if ($taxYear === null && $document->effective_date?->year === $year) {
            return [$document->effective_date->month];
        }

        return [];
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

    /**
     * @param  array<int, int>  $months
     * @return array<int, string>
     */
    protected function monthLabels(array $months): array
    {
        return collect($months)
            ->map(fn (int $month): string => Carbon::createFromDate(2000, $month, 1)->format('M'))
            ->values()
            ->all();
    }

    protected function transactionText(PersonalTransaction $transaction): string
    {
        return strtolower(trim(implode(' ', array_filter([
            $transaction->merchant_name,
            $transaction->description,
        ]))));
    }

    protected function looksLikeTransferMovement(string $text): bool
    {
        if (str_contains($text, 'paypal inst xfer')) {
            return false;
        }

        if (str_contains($text, 'zelle from')) {
            return false;
        }

        return $this->containsAny($text, [
            'transfer',
            'xfer',
            'zelle',
            'venmo',
            'cash app',
            'wire',
            'ach transfer',
        ]);
    }

    protected function looksLikeOwnerActivity(string $text): bool
    {
        return $this->containsAny($text, [
            'owner draw',
            'owner contribution',
            'shareholder distribution',
            'shareholder contribution',
            'member draw',
            'member contribution',
            'capital contribution',
            'business personal',
        ]);
    }

    protected function looksLikeLoanActivity(string $text): bool
    {
        return $this->containsAny($text, [
            'shareholder loan',
            'owner loan',
            'loan from owner',
            'loan to owner',
            'member loan',
            'officer loan',
            'loan receivable',
            'loan payable',
            'note receivable',
            'note payable',
            'due to owner',
            'due from owner',
        ]);
    }

    protected function looksLikeReimbursementActivity(string $text): bool
    {
        return $this->containsAny($text, [
            'reimbursement',
            'reimburse',
            'expense report',
            'accountable plan',
        ]);
    }

    protected function categoryLooksLikeOwnerActivity(string $categoryName): bool
    {
        return $this->containsAny($categoryName, [
            'business <> personal',
            'business personal',
            'owner',
            'shareholder',
            'distribution',
            'contribution',
            'draw',
        ]);
    }

    protected function businessPersonalMovementSubtype(string $text, string $categoryName): ?string
    {
        if ($this->looksLikeLoanActivity($text) || $this->looksLikeLoanActivity($categoryName)) {
            return 'loan_activity';
        }

        if ($this->looksLikeReimbursementActivity($text) || $this->looksLikeReimbursementActivity($categoryName)) {
            return 'reimbursement';
        }

        if ($this->looksLikeOwnerActivity($text) || $this->categoryLooksLikeOwnerActivity($categoryName)) {
            return 'owner_equity';
        }

        return null;
    }

    /**
     * @param  array<string, int|float>  $summary
     */
    protected function incrementMovementSubtypeCount(array &$summary, string $movementSubtype, float $amount): void
    {
        switch ($movementSubtype) {
            case 'loan_activity':
                $summary['loan_activity_count']++;
                $summary['loan_activity_total'] += $amount;
                break;

            case 'reimbursement':
                $summary['reimbursement_count']++;
                $summary['reimbursement_total'] += $amount;
                break;

            default:
                $summary['owner_equity_count']++;
                $summary['owner_equity_total'] += $amount;
                break;
        }
    }

    /**
     * @param  array<string, int|float>  $summary
     */
    protected function incrementMovementSubtypeReviewCount(array &$summary, string $movementSubtype, float $amount): void
    {
        switch ($movementSubtype) {
            case 'loan_activity':
                $summary['loan_activity_review_count']++;
                $summary['loan_activity_review_total'] += $amount;
                break;

            case 'reimbursement':
                $summary['reimbursement_review_count']++;
                $summary['reimbursement_review_total'] += $amount;
                break;

            default:
                $summary['owner_equity_review_count']++;
                $summary['owner_equity_review_total'] += $amount;
                break;
        }
    }

    /**
     * @param  array<int, string>  $needles
     */
    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
