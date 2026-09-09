<?php

namespace App\Agents\Tools;

use App\Models\ContractorInvoice;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;
use Carbon\Carbon;

class FinancialForecastTool extends BaseTool
{
    protected QuickBooksApiService $qboService;

    public function __construct(QuickBooksApiService $qboService)
    {
        $this->qboService = $qboService;
    }

    public function category(): string
    {
        return 'financial';
    }

    public function name(): string
    {
        return 'Financial Forecast';
    }

    public function description(): string
    {
        return 'Generate revenue and expense projections based on historical data and known upcoming obligations. Useful for cash flow planning.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'months_ahead' => [
                    'type' => 'integer',
                    'description' => 'Number of months to forecast. Default: 3.',
                    'default' => 3,
                    'minimum' => 1,
                    'maximum' => 12,
                ],
                'include_contractor_payments' => [
                    'type' => 'boolean',
                    'description' => 'Include pending contractor payments in expense forecast. Default: true.',
                    'default' => true,
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $connection = QuickBooksConnection::active()->first();

        if (! $connection) {
            return [
                'success' => false,
                'error' => 'No active QuickBooks connection found.',
            ];
        }

        $monthsAhead = min(12, max(1, $params['months_ahead'] ?? 3));
        $includeContractors = $params['include_contractor_payments'] ?? true;

        try {
            // Get historical data for the past 6 months
            $historicalData = $this->getHistoricalData($connection);

            // Calculate averages and trends
            $trends = $this->calculateTrends($historicalData);

            // Get known upcoming obligations
            $knownExpenses = $this->getKnownExpenses($connection, $monthsAhead, $includeContractors);
            $knownIncome = $this->getKnownIncome($connection, $monthsAhead);

            // Generate forecast
            $forecast = $this->generateForecast($trends, $knownExpenses, $knownIncome, $monthsAhead);

            return [
                'success' => true,
                'forecast_period' => [
                    'start' => now()->format('Y-m-d'),
                    'end' => now()->addMonths($monthsAhead)->format('Y-m-d'),
                    'months' => $monthsAhead,
                ],
                'historical_summary' => [
                    'avg_monthly_revenue' => round($trends['avg_revenue'], 2),
                    'avg_monthly_expenses' => round($trends['avg_expenses'], 2),
                    'avg_monthly_profit' => round($trends['avg_profit'], 2),
                    'revenue_trend' => $trends['revenue_trend'],
                    'expense_trend' => $trends['expense_trend'],
                ],
                'known_obligations' => [
                    'expected_income' => round(array_sum(array_column($knownIncome, 'amount')), 2),
                    'expected_expenses' => round(array_sum(array_column($knownExpenses, 'amount')), 2),
                ],
                'monthly_forecast' => $forecast,
                'totals' => [
                    'projected_revenue' => round(array_sum(array_column($forecast, 'projected_revenue')), 2),
                    'projected_expenses' => round(array_sum(array_column($forecast, 'projected_expenses')), 2),
                    'projected_profit' => round(array_sum(array_column($forecast, 'projected_profit')), 2),
                ],
                'confidence' => $this->assessConfidence($historicalData, $monthsAhead),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to generate forecast: '.$e->getMessage(),
            ];
        }
    }

    protected function getHistoricalData(QuickBooksConnection $connection): array
    {
        $months = [];

        // Get data for the past 6 months
        for ($i = 6; $i >= 1; $i--) {
            $date = now()->subMonths($i);
            $startDate = $date->copy()->startOfMonth()->format('Y-m-d');
            $endDate = $date->copy()->endOfMonth()->format('Y-m-d');

            try {
                $pnl = $this->qboService->getProfitAndLoss($connection, $startDate, $endDate);

                $months[] = [
                    'month' => $date->format('Y-m'),
                    'revenue' => $this->extractPnlValue($pnl, 'TotalIncome') ?? 0,
                    'expenses' => $this->extractPnlValue($pnl, 'TotalExpenses') ?? 0,
                    'profit' => $this->extractPnlValue($pnl, 'NetIncome') ?? 0,
                ];
            } catch (\Exception $e) {
                // Skip months with errors
                continue;
            }
        }

        return $months;
    }

    protected function calculateTrends(array $historicalData): array
    {
        if (empty($historicalData)) {
            return [
                'avg_revenue' => 0,
                'avg_expenses' => 0,
                'avg_profit' => 0,
                'revenue_trend' => 'stable',
                'expense_trend' => 'stable',
            ];
        }

        $revenues = array_column($historicalData, 'revenue');
        $expenses = array_column($historicalData, 'expenses');
        $profits = array_column($historicalData, 'profit');

        $avgRevenue = array_sum($revenues) / count($revenues);
        $avgExpenses = array_sum($expenses) / count($expenses);
        $avgProfit = array_sum($profits) / count($profits);

        // Simple trend detection (compare first half to second half)
        $midpoint = (int) floor(count($revenues) / 2);
        $firstHalfRevenue = array_sum(array_slice($revenues, 0, $midpoint)) / max(1, $midpoint);
        $secondHalfRevenue = array_sum(array_slice($revenues, $midpoint)) / max(1, count($revenues) - $midpoint);

        $firstHalfExpenses = array_sum(array_slice($expenses, 0, $midpoint)) / max(1, $midpoint);
        $secondHalfExpenses = array_sum(array_slice($expenses, $midpoint)) / max(1, count($expenses) - $midpoint);

        $revenueTrend = $this->determineTrend($firstHalfRevenue, $secondHalfRevenue);
        $expenseTrend = $this->determineTrend($firstHalfExpenses, $secondHalfExpenses);

        return [
            'avg_revenue' => $avgRevenue,
            'avg_expenses' => $avgExpenses,
            'avg_profit' => $avgProfit,
            'revenue_trend' => $revenueTrend,
            'expense_trend' => $expenseTrend,
            'revenue_growth_rate' => $firstHalfRevenue > 0
                ? round(($secondHalfRevenue - $firstHalfRevenue) / $firstHalfRevenue * 100, 1)
                : 0,
            'expense_growth_rate' => $firstHalfExpenses > 0
                ? round(($secondHalfExpenses - $firstHalfExpenses) / $firstHalfExpenses * 100, 1)
                : 0,
        ];
    }

    protected function determineTrend(float $first, float $second): string
    {
        if ($first == 0) {
            return 'stable';
        }

        $change = ($second - $first) / $first * 100;

        if ($change > 10) {
            return 'increasing';
        } elseif ($change < -10) {
            return 'decreasing';
        }

        return 'stable';
    }

    protected function getKnownExpenses(QuickBooksConnection $connection, int $months, bool $includeContractors): array
    {
        $expenses = [];

        // Get open bills from QBO
        try {
            $bills = $this->qboService->getOpenBills($connection);
            foreach ($bills as $bill) {
                if (isset($bill['DueDate']) && Carbon::parse($bill['DueDate'])->isBefore(now()->addMonths($months))) {
                    $expenses[] = [
                        'type' => 'bill',
                        'vendor' => $bill['VendorRef']['name'] ?? 'Unknown',
                        'amount' => $bill['Balance'] ?? $bill['TotalAmt'] ?? 0,
                        'due_date' => $bill['DueDate'],
                    ];
                }
            }
        } catch (\Exception $e) {
            // Continue without bills
        }

        // Get pending contractor payments
        if ($includeContractors) {
            $contractorInvoices = ContractorInvoice::where('status', ContractorInvoice::STATUS_APPROVED)
                ->orWhere('status', ContractorInvoice::STATUS_SUBMITTED)
                ->get();

            foreach ($contractorInvoices as $invoice) {
                $expenses[] = [
                    'type' => 'contractor',
                    'vendor' => $invoice->contractor?->display_name ?? 'Contractor',
                    'amount' => $invoice->amount,
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                ];
            }
        }

        return $expenses;
    }

    protected function getKnownIncome(QuickBooksConnection $connection, int $months): array
    {
        $income = [];

        // Get open invoices from QBO
        try {
            $invoices = $this->qboService->getOpenInvoices($connection);
            foreach ($invoices as $invoice) {
                if (isset($invoice['DueDate']) && Carbon::parse($invoice['DueDate'])->isBefore(now()->addMonths($months))) {
                    $income[] = [
                        'type' => 'invoice',
                        'customer' => $invoice['CustomerRef']['name'] ?? 'Unknown',
                        'amount' => $invoice['Balance'] ?? $invoice['TotalAmt'] ?? 0,
                        'due_date' => $invoice['DueDate'],
                    ];
                }
            }
        } catch (\Exception $e) {
            // Continue without invoices
        }

        return $income;
    }

    protected function generateForecast(array $trends, array $knownExpenses, array $knownIncome, int $months): array
    {
        $forecast = [];

        for ($i = 1; $i <= $months; $i++) {
            $monthDate = now()->addMonths($i);
            $monthKey = $monthDate->format('Y-m');

            // Apply growth rate to base averages
            $growthMultiplier = 1 + ($trends['revenue_growth_rate'] ?? 0) / 100 * ($i / 6);
            $expenseMultiplier = 1 + ($trends['expense_growth_rate'] ?? 0) / 100 * ($i / 6);

            $baseRevenue = ($trends['avg_revenue'] ?? 0) * $growthMultiplier;
            $baseExpenses = ($trends['avg_expenses'] ?? 0) * $expenseMultiplier;

            // Add known items for this month
            $knownRevenueThisMonth = array_sum(array_map(function ($item) use ($monthKey) {
                if (! isset($item['due_date'])) {
                    return 0;
                }

                return Carbon::parse($item['due_date'])->format('Y-m') === $monthKey ? $item['amount'] : 0;
            }, $knownIncome));

            $knownExpensesThisMonth = array_sum(array_map(function ($item) use ($monthKey) {
                if (! isset($item['due_date'])) {
                    return 0;
                }

                return Carbon::parse($item['due_date'])->format('Y-m') === $monthKey ? $item['amount'] : 0;
            }, $knownExpenses));

            // Combine baseline with known items (don't double count)
            $projectedRevenue = max($baseRevenue, $knownRevenueThisMonth);
            $projectedExpenses = $baseExpenses + $knownExpensesThisMonth;

            $forecast[] = [
                'month' => $monthKey,
                'month_name' => $monthDate->format('F Y'),
                'projected_revenue' => round($projectedRevenue, 2),
                'projected_expenses' => round($projectedExpenses, 2),
                'projected_profit' => round($projectedRevenue - $projectedExpenses, 2),
                'known_income' => round($knownRevenueThisMonth, 2),
                'known_expenses' => round($knownExpensesThisMonth, 2),
            ];
        }

        return $forecast;
    }

    protected function assessConfidence(array $historicalData, int $monthsAhead): array
    {
        $dataPoints = count($historicalData);

        if ($dataPoints < 3) {
            $level = 'low';
            $reason = 'Limited historical data (less than 3 months)';
        } elseif ($dataPoints < 6) {
            $level = 'medium';
            $reason = 'Moderate historical data available';
        } else {
            $level = 'high';
            $reason = 'Good historical data available';
        }

        // Reduce confidence for longer forecasts
        if ($monthsAhead > 6 && $level !== 'low') {
            $level = $level === 'high' ? 'medium' : 'low';
            $reason .= '; long forecast period reduces accuracy';
        }

        return [
            'level' => $level,
            'reason' => $reason,
            'historical_months' => $dataPoints,
        ];
    }

    protected function extractPnlValue(array $pnl, string $key): ?float
    {
        $rows = $pnl['Rows']['Row'] ?? [];

        foreach ($rows as $row) {
            if (isset($row['group']) && $row['group'] === $key) {
                return (float) ($row['Summary']['ColData'][1]['value'] ?? 0);
            }
            if (isset($row['type']) && $row['type'] === 'Section') {
                foreach ($row['Rows']['Row'] ?? [] as $subRow) {
                    if (isset($subRow['group']) && $subRow['group'] === $key) {
                        return (float) ($subRow['Summary']['ColData'][1]['value'] ?? 0);
                    }
                }
            }
        }

        return null;
    }
}
