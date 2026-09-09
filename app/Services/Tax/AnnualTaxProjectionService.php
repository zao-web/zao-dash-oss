<?php

namespace App\Services\Tax;

use App\Models\Client;
use App\Models\EstimatedTaxPayment;
use App\Models\Invoice;
use App\Models\TaxProfile;
use App\Services\PersonalFinance\BookkeepingReadinessService;

/**
 * Living annual tax projection that updates as data changes.
 * Combines YTD actuals + projected future income + TaxBracketEngine.
 */
class AnnualTaxProjectionService
{
    public function __construct(
        protected BookkeepingReadinessService $bookkeepingReadinessService,
    ) {}

    /**
     * Generate a comprehensive annual tax projection.
     *
     * @return array{
     *     year: int,
     *     ytd_revenue: float,
     *     ytd_expenses: float,
     *     ytd_net: float,
     *     projected_annual_revenue: float,
     *     projected_annual_expenses: float,
     *     projected_annual_net: float,
     *     tax_computation: array,
     *     payments_made: array,
     *     remaining_tax_due: float,
     *     safe_harbor_status: string,
     *     quarterly_breakdown: array,
     *     plain_english_summary: string,
     * }
     */
    public function project(int $userId, ?int $year = null): array
    {
        $year = $year ?? now()->year;
        $currentMonth = now()->month;
        $remainingMonths = 12 - $currentMonth;

        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->first();

        // YTD actuals from paid invoices
        $paidInvoiceQuery = Invoice::where('status', 'paid')
            ->whereYear('paid_at', $year);

        $ytdRevenue = (float) (clone $paidInvoiceQuery)->sum('total');
        $ytdRecurringRevenue = (float) (clone $paidInvoiceQuery)->where('is_recurring', true)->sum('total');

        // Add retainer income
        $retainerMonthly = (float) Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->sum('recurring_invoice_amount');

        // Project remaining months without double-counting retainer revenue already present in YTD invoices.
        $monthlyAvgNonRecurringRevenue = $currentMonth > 0
            ? max(0, $ytdRevenue - $ytdRecurringRevenue) / $currentMonth
            : 0;
        $projectedRemainingRevenue = ($retainerMonthly + $monthlyAvgNonRecurringRevenue) * $remainingMonths;
        $projectedAnnualRevenue = $ytdRevenue + $projectedRemainingRevenue;

        $bookkeepingReadiness = $this->bookkeepingReadinessService->summarize($userId, $year);
        $ledgerSummary = $bookkeepingReadiness['ledger_summary'] ?? [];
        $bookExpenseYtd = (float) ($ledgerSummary['book_expenses'] ?? 0);
        $canUseBookExpenses = (bool) ($bookkeepingReadiness['close_ready'] ?? false)
            || (($bookkeepingReadiness['source_of_truth']['code'] ?? null) === 'quickbooks_plus_bank');

        // Expenses — use closed books when trustworthy, otherwise fall back to budget proxy
        $budgetService = app(\App\Services\PersonalFinance\BudgetService::class);
        $budgetStatus = $budgetService->getMonthlyBudgetStatus($userId);
        $monthlyExpenses = collect($budgetStatus['categories'] ?? [])
            ->filter(fn ($c) => in_array($c['category_type'], ['expense', 'debt_payment', 'tax_payment']))
            ->sum('target');
        $ytdExpenses = $canUseBookExpenses && $currentMonth > 0
            ? $bookExpenseYtd
            : $monthlyExpenses * $currentMonth;
        $projectedAnnualExpenses = $canUseBookExpenses && $currentMonth > 0
            ? ($bookExpenseYtd / $currentMonth) * 12
            : $monthlyExpenses * 12;

        $projectedAnnualNet = max(0, $projectedAnnualRevenue - $projectedAnnualExpenses);

        // Compute tax
        $salary = $profile ? (float) $profile->reasonable_salary : $projectedAnnualNet * 0.40;
        $filingStatus = $profile->filing_status ?? 'single';
        $qualifyingChildren = $profile ? $profile->dependent_count : 0;
        $isPortland = $profile && strtolower($profile->resident_city ?? '') === 'portland';

        $taxResult = TaxBracketEngine::computeSCorpTax(
            netBusinessIncome: $projectedAnnualNet,
            salary: min($salary, $projectedAnnualNet),
            year: $year,
            filingStatus: $filingStatus,
            qualifyingChildren: $qualifyingChildren,
            isPortlandResident: $isPortland,
            healthInsurance: (float) ($profile->health_insurance_annual ?? 0),
        );

        // Payments made
        $payments = EstimatedTaxPayment::ytdPaymentsByJurisdiction($userId, $year);

        // Safe harbor
        $priorYearTax = (float) ($profile->prior_year_tax_liability ?? 0);
        $priorYearAgi = (float) ($profile->prior_year_agi ?? 0);
        $safeHarborTarget = $priorYearAgi > 150000
            ? $priorYearTax * 1.10
            : $priorYearTax;

        $totalTaxLiability = $taxResult['total_tax'];
        $totalPaid = $payments['total'];
        $remainingDue = max(0, $totalTaxLiability - $totalPaid);

        $safeHarborStatus = match (true) {
            $totalPaid >= $safeHarborTarget => 'on_track',
            $totalPaid >= $safeHarborTarget * 0.75 => 'slightly_behind',
            $totalPaid > 0 => 'underpaid',
            default => 'no_payments',
        };

        // Quarterly breakdown
        $quarterlyTarget = $totalTaxLiability / 4;
        $quarterlyBreakdown = [];
        for ($q = 1; $q <= 4; $q++) {
            $qPayments = (float) EstimatedTaxPayment::where('user_id', $userId)
                ->forYear($year)->forQuarter($q)->confirmed()->federal()->sum('amount');
            $quarterlyBreakdown[] = [
                'quarter' => $q,
                'target' => round($quarterlyTarget, 2),
                'paid' => round($qPayments, 2),
                'remaining' => round(max(0, $quarterlyTarget - $qPayments), 2),
                'status' => $qPayments >= $quarterlyTarget ? 'paid' : ($q * 3 <= $currentMonth ? 'due' : 'upcoming'),
            ];
        }

        // Plain English summary
        $summary = $this->buildSummary(
            $projectedAnnualRevenue, $projectedAnnualNet, $totalTaxLiability,
            $totalPaid, $remainingDue, $taxResult['effective_rate'], $safeHarborStatus
        );

        return [
            'year' => $year,
            'ytd_revenue' => round($ytdRevenue, 2),
            'ytd_expenses' => round($ytdExpenses, 2),
            'ytd_net' => round($ytdRevenue - $ytdExpenses, 2),
            'projected_annual_revenue' => round($projectedAnnualRevenue, 2),
            'projected_annual_expenses' => round($projectedAnnualExpenses, 2),
            'projected_annual_net' => round($projectedAnnualNet, 2),
            'retainer_monthly' => round($retainerMonthly, 2),
            'tax_computation' => $taxResult,
            'payments_made' => $payments,
            'total_tax_liability' => round($totalTaxLiability, 2),
            'remaining_tax_due' => round($remainingDue, 2),
            'safe_harbor_status' => $safeHarborStatus,
            'safe_harbor_target' => round($safeHarborTarget, 2),
            'quarterly_breakdown' => $quarterlyBreakdown,
            'plain_english_summary' => $summary,
        ];
    }

    protected function buildSummary(
        float $revenue, float $net, float $tax,
        float $paid, float $remaining, float $effectiveRate, string $safeHarbor
    ): string {
        $lines = [];

        $lines[] = "You're on track to earn about \$".number_format($revenue, 0).' this year.';
        $lines[] = 'After expenses, your net income should be around $'.number_format($net, 0).'.';
        $lines[] = 'Your total projected tax liability is $'.number_format($tax, 0).
            ' (effective rate: '.number_format($effectiveRate, 1).'%).';

        if ($paid > 0) {
            $lines[] = "You've paid \$".number_format($paid, 0).' in estimated taxes so far.';
        }

        if ($remaining > 0) {
            $lines[] = 'You still owe about $'.number_format($remaining, 0).' for the rest of the year.';
        }

        $lines[] = match ($safeHarbor) {
            'on_track' => 'You\'re meeting safe harbor requirements — no underpayment penalty expected.',
            'slightly_behind' => 'You\'re slightly behind on safe harbor. Consider making an extra payment soon.',
            'underpaid' => 'Warning: You\'re significantly underpaid on estimated taxes. Make a payment to avoid penalties.',
            'no_payments' => 'No estimated tax payments recorded yet. You should start paying quarterly.',
        };

        return implode(' ', $lines);
    }
}
