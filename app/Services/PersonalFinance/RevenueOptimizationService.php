<?php

namespace App\Services\PersonalFinance;

use App\Models\Client;
use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\Invoice;
use App\Models\TaxObligation;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;

class RevenueOptimizationService
{
    /**
     * Calculate the gap between monthly obligations and monthly income.
     *
     * @return array{
     *     monthly_obligations: float,
     *     monthly_income: float,
     *     gap: float,
     *     is_deficit: bool,
     *     hours_needed: int|null,
     *     average_rate: float,
     *     breakdown: array{
     *         debt_minimums: float,
     *         tax_installments: float,
     *         operating_expenses: float,
     *         invoice_income: float,
     *         retainer_income: float,
     *     },
     * }
     */
    public function calculateRevenueGap(int $userId): array
    {
        $debtMinimums = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->sum('minimum_payment');

        $taxInstallments = TaxObligation::whereHas('debt', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('installment_monthly')
            ->sum('installment_monthly');

        // Use budget as primary source for monthly obligations if available
        // Only sum expense/debt/tax categories — NOT income budgets
        $budgetService = app(BudgetService::class);
        $budgetStatus = $budgetService->getMonthlyBudgetStatus($userId);
        $budgetedExpenses = collect($budgetStatus['categories'] ?? [])
            ->filter(fn ($cat) => in_array($cat['category_type'], ['expense', 'debt_payment', 'tax_payment']))
            ->sum('target');

        // If budget exists and is meaningful, use it; otherwise fall back to snapshot
        if ($budgetedExpenses > 0) {
            $totalObligations = $budgetedExpenses;
        } else {
            $snapshot = FinancialSnapshot::orderBy('created_at', 'desc')->first();
            $monthlyExpenses = (float) ($snapshot?->total_expenses ?? 0);
            $totalObligations = $debtMinimums + $taxInstallments + $monthlyExpenses;
        }

        $threeMonthsAgo = now()->subMonths(3);
        $totalPaid = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $threeMonthsAgo)
            ->sum('total');
        $invoiceIncome = (float) $totalPaid / 3;

        $retainerIncome = Client::where('recurring_invoice_enabled', true)
            ->sum('recurring_invoice_amount');
        $monthlyIncome = $invoiceIncome + (float) $retainerIncome;

        $gap = $totalObligations - $monthlyIncome;

        $avgRate = $this->getAverageEffectiveRate();
        $hoursNeeded = $avgRate > 0 && $gap > 0 ? (int) ceil($gap / $avgRate) : null;

        return [
            'monthly_obligations' => round($totalObligations, 2),
            'monthly_income' => round($monthlyIncome, 2),
            'gap' => round($gap, 2),
            'is_deficit' => $gap > 0,
            'hours_needed' => $hoursNeeded,
            'average_rate' => round($avgRate, 2),
            'breakdown' => [
                'debt_minimums' => round((float) $debtMinimums, 2),
                'tax_installments' => round((float) $taxInstallments, 2),
                'budgeted_expenses' => round($budgetedExpenses, 2),
                'obligations_source' => $budgetedExpenses > 0 ? 'budget' : 'snapshot',
                'invoice_income' => round($invoiceIncome, 2),
                'retainer_income' => round((float) $retainerIncome, 2),
            ],
        ];
    }

    /**
     * Get client profitability ranked by margin.
     *
     * @return array<int, array{
     *     client_id: int,
     *     client_name: string,
     *     revenue: float,
     *     hours: float,
     *     cost: float,
     *     profit: float,
     *     margin: float,
     *     effective_rate: float,
     * }>
     */
    public function getClientProfitability(?string $period = null): array
    {
        $query = Invoice::where('status', 'paid')
            ->whereNotNull('client_id')
            ->with('client');

        if ($period) {
            $months = match ($period) {
                'quarterly' => 3,
                'yearly' => 12,
                default => 1,
            };
            $query->where('paid_at', '>=', now()->subMonths($months));
        }

        $invoicesByClient = $query->get()->groupBy('client_id');

        $results = [];

        foreach ($invoicesByClient as $clientId => $invoices) {
            $client = $invoices->first()->client;

            if (! $client) {
                continue;
            }

            $revenue = $invoices->sum('total');

            $timeQuery = TimeEntry::where('client_id', $clientId);

            if ($period) {
                $months = match ($period) {
                    'quarterly' => 3,
                    'yearly' => 12,
                    default => 1,
                };
                $timeQuery->where('spent_date', '>=', now()->subMonths($months));
            }

            $hours = $timeQuery->sum('hours');
            $cost = $timeQuery->sum(DB::raw('hours * cost_rate'));

            $profit = $revenue - $cost;
            $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
            $effectiveRate = $hours > 0 ? $revenue / $hours : 0;

            $results[] = [
                'client_id' => $clientId,
                'client_name' => $client->name,
                'revenue' => round((float) $revenue, 2),
                'hours' => round((float) $hours, 1),
                'cost' => round((float) $cost, 2),
                'profit' => round((float) $profit, 2),
                'margin' => round($margin, 1),
                'effective_rate' => round($effectiveRate, 2),
            ];
        }

        usort($results, fn ($a, $b) => $b['margin'] <=> $a['margin']);

        return $results;
    }

    /**
     * Calculate utilization rate (billable vs total hours).
     *
     * @return array{total_hours: float, billable_hours: float, utilization_rate: float}
     */
    public function getUtilizationRate(?string $period = null): array
    {
        $query = TimeEntry::query();

        if ($period) {
            $months = match ($period) {
                'quarterly' => 3,
                'yearly' => 12,
                default => 1,
            };
            $query->where('spent_date', '>=', now()->subMonths($months));
        } else {
            $query->where('spent_date', '>=', now()->subMonth());
        }

        $totalHours = (clone $query)->sum('hours');
        $billableHours = (clone $query)->where('is_billable', true)->sum('hours');

        return [
            'total_hours' => round((float) $totalHours, 1),
            'billable_hours' => round((float) $billableHours, 1),
            'utilization_rate' => $totalHours > 0 ? round(($billableHours / $totalHours) * 100, 1) : 0,
        ];
    }

    /**
     * Calculate average effective rate from last 3 months.
     */
    public function getAverageEffectiveRate(): float
    {
        $threeMonthsAgo = now()->subMonths(3);
        $revenue = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', $threeMonthsAgo)
            ->sum('total');
        $hours = TimeEntry::where('is_billable', true)
            ->where('spent_date', '>=', $threeMonthsAgo)
            ->sum('hours');

        return $hours > 0 ? (float) $revenue / (float) $hours : 0;
    }
}
