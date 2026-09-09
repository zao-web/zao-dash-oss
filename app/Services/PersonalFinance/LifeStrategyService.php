<?php

namespace App\Services\PersonalFinance;

use App\Models\Invoice;
use App\Services\Tax\TaxOptimizationService;

class LifeStrategyService
{
    public function __construct(
        protected DebtManagementService $debtService,
        protected CashFlowService $cashFlowService,
        protected RevenueOptimizationService $revenueService,
        protected BudgetService $budgetService,
        protected TaxOptimizationService $taxService,
        protected FinancialPhaseService $phaseService,
        protected NorthStarService $northStarService,
        protected InvoiceVelocityService $velocityService,
    ) {}

    /**
     * Generate a complete holistic financial strategy.
     * This is THE function -- it sees everything and recommends everything.
     *
     * @return array<string, mixed>
     */
    public function generateStrategy(int $userId): array
    {
        // 1. Gather ALL data
        $phase = $this->phaseService->detectPhase($userId);
        $debtSummary = $this->debtService->getDebtSummary($userId);
        $revenueGap = $this->revenueService->calculateRevenueGap($userId);
        $forecast = $this->cashFlowService->generateForecast($userId, 90);
        $budgetStatus = $this->budgetService->getMonthlyBudgetStatus($userId);
        $northStar = $this->northStarService->getActiveGoal($userId);
        $profitability = $this->revenueService->getClientProfitability();
        $utilization = $this->revenueService->getUtilizationRate();
        $dso = $this->velocityService->getOverallDso();

        // 2. Compute the holistic picture
        $monthlyIncome = $revenueGap['monthly_income'];
        $monthlyObligations = $revenueGap['monthly_obligations'];
        $totalDebt = $debtSummary['total_balance'];
        $monthlyMinimums = $debtSummary['total_minimum_payments'];
        $taxDebt = $debtSummary['tax_debt_total'];
        $collectionsDebt = $debtSummary['collections_total'];

        // Available after mandatory expenses (can be negative = deficit)
        $disposableIncome = $monthlyIncome - $monthlyObligations;

        // 3. Generate expense cutting opportunities
        $expenseCuts = $this->identifyExpenseCuts($userId, $budgetStatus);

        // 4. Calculate tax savings opportunities
        $taxSavings = $this->calculateTaxSavingsOpportunity($monthlyIncome * 12, $userId);

        // 5. Calculate revenue velocity needed for North Star
        $velocityNeeded = $this->calculateRequiredVelocity($userId, $totalDebt, $monthlyIncome, $disposableIncome, $northStar);

        // 6. Generate prioritized action plan
        $actionPlan = $this->generateActionPlan($phase, $debtSummary, $revenueGap, $forecast, $expenseCuts, $taxSavings, $velocityNeeded);

        // 7. Project North Star timeline
        $northStarTimeline = $this->projectNorthStarTimeline($totalDebt, $disposableIncome, $expenseCuts, $taxSavings, $velocityNeeded, $northStar);

        return [
            'generated_at' => now()->toIso8601String(),
            'phase' => $phase,

            // The complete financial picture
            'snapshot' => [
                'monthly_income' => round($monthlyIncome, 2),
                'monthly_obligations' => round($monthlyObligations, 2),
                'disposable_income' => round($disposableIncome, 2),
                'total_debt' => round($totalDebt, 2),
                'tax_debt' => round($taxDebt, 2),
                'collections_debt' => round($collectionsDebt, 2),
                'monthly_minimums' => round($monthlyMinimums, 2),
                'utilization_rate' => $utilization['utilization_rate'],
                'average_dso' => $dso,
                'net_cash_position' => round($forecast['current_cash']['total'] ?? 0, 2),
            ],

            // What to cut
            'expense_optimization' => [
                'personal_cuts' => $expenseCuts['personal'],
                'business_cuts' => $expenseCuts['business'],
                'total_monthly_savings' => $expenseCuts['total_monthly_savings'],
                'total_annual_savings' => $expenseCuts['total_annual_savings'],
            ],

            // Tax strategy
            'tax_optimization' => $taxSavings,

            // Revenue targets
            'revenue_targets' => $velocityNeeded,

            // Client intelligence
            'client_intelligence' => [
                'most_profitable' => array_slice($profitability, 0, 3),
                'least_profitable' => array_slice(array_reverse($profitability), 0, 3),
                'average_dso' => $dso,
                'utilization' => $utilization,
            ],

            // The North Star projection
            'north_star_projection' => $northStarTimeline,

            // Prioritized action plan
            'action_plan' => $actionPlan,

            // Cash flow outlook
            'cash_flow_outlook' => [
                'first_shortfall_date' => $forecast['summary']['shortfall_count'] > 0
                    ? ($forecast['summary']['shortfalls'][0]['date'] ?? null) : null,
                'ending_balance_90_days' => $forecast['summary']['ending_balance'],
                'lowest_balance' => $forecast['summary']['lowest_balance'],
                'lowest_balance_date' => $forecast['summary']['lowest_balance_date'],
            ],
        ];
    }

    /**
     * Identify specific expenses that can be cut.
     *
     * @return array{personal: array, business: array, total_monthly_savings: float, total_annual_savings: float}
     */
    protected function identifyExpenseCuts(int $userId, array $budgetStatus): array
    {
        $personalCuts = [];
        $businessCuts = [];
        $totalSavings = 0;

        // Analyze over-budget categories
        foreach ($budgetStatus['categories'] ?? [] as $cat) {
            if ($cat['status'] === 'over' && $cat['spent'] > $cat['target']) {
                $overage = $cat['spent'] - $cat['target'];
                $cut = [
                    'category' => $cat['category_name'],
                    'current_spending' => $cat['spent'],
                    'budget_target' => $cat['target'],
                    'potential_savings' => round($overage, 2),
                    'action' => "Reduce {$cat['category_name']} spending by \$".number_format($overage, 2).'/month to meet your budget target.',
                ];

                // Classify as personal or business
                if (in_array($cat['category_name'], ['Software/SaaS', 'Office Supplies', 'Professional Services', 'Travel', 'Equipment', 'Home Office'])) {
                    $businessCuts[] = $cut;
                } else {
                    $personalCuts[] = $cut;
                }
                $totalSavings += $overage;
            }
        }

        // Check for high discretionary spending even if within budget
        $discretionaryCategories = ['Restaurants', 'Fast Food', 'Coffee Shops', 'Entertainment', 'Subscriptions', 'Clothing'];
        foreach ($budgetStatus['categories'] ?? [] as $cat) {
            if (in_array($cat['category_name'], $discretionaryCategories) && $cat['spent'] > 100) {
                // In crisis/stabilizing, suggest cutting discretionary to minimum
                $suggestedCut = max(0, $cat['spent'] - 50); // Leave $50/month minimum
                if ($suggestedCut > 0 && $cat['status'] !== 'over') {
                    $personalCuts[] = [
                        'category' => $cat['category_name'],
                        'current_spending' => $cat['spent'],
                        'potential_savings' => round($suggestedCut, 2),
                        'action' => "Consider cutting {$cat['category_name']} from \$".number_format($cat['spent'], 2).' to $50/month -- saves $'.number_format($suggestedCut, 2).'/month.',
                    ];
                    $totalSavings += $suggestedCut;
                }
            }
        }

        // Uncategorized spending is a red flag
        if (($budgetStatus['uncategorized_spending'] ?? 0) > 100) {
            $personalCuts[] = [
                'category' => 'Uncategorized',
                'current_spending' => $budgetStatus['uncategorized_spending'],
                'potential_savings' => round($budgetStatus['uncategorized_spending'] * 0.3, 2),
                'action' => 'You have $'.number_format($budgetStatus['uncategorized_spending'], 2).' in uncategorized spending. Categorize it to find more savings.',
            ];
        }

        return [
            'personal' => $personalCuts,
            'business' => $businessCuts,
            'total_monthly_savings' => round($totalSavings, 2),
            'total_annual_savings' => round($totalSavings * 12, 2),
        ];
    }

    /**
     * Calculate total tax savings available.
     *
     * @return array{total_annual_savings: float, strategies: array, effective_rate_without: float, effective_rate_with: float, monthly_tax_savings: float}
     */
    protected function calculateTaxSavingsOpportunity(float $annualIncome, int $userId): array
    {
        if ($annualIncome <= 0) {
            return ['total_annual_savings' => 0, 'strategies' => [], 'effective_rate_without' => 0, 'effective_rate_with' => 0, 'monthly_tax_savings' => 0];
        }

        $salary = $annualIncome * 0.35; // Conservative S-Corp salary
        $stacked = $this->taxService->getStackedSavings($annualIncome, $salary, 40); // Default age 40

        return [
            'total_annual_savings' => $stacked['total_savings'],
            'strategies' => $stacked['strategies'],
            'effective_rate_without' => $stacked['effective_rate_without'],
            'effective_rate_with' => $stacked['effective_rate_with'],
            'monthly_tax_savings' => round($stacked['total_savings'] / 12, 2),
        ];
    }

    /**
     * Calculate the revenue velocity needed to hit the North Star.
     *
     * @param  array<string, mixed>|null  $northStar
     * @return array<string, mixed>
     */
    protected function calculateRequiredVelocity(int $userId, float $totalDebt, float $monthlyIncome, float $disposableIncome, ?array $northStar): array
    {
        $northStarCost = $northStar['total_cost_estimate'] ?? 0;
        $totalNeeded = $totalDebt + $northStarCost;

        // How many months at current rate?
        $monthsAtCurrentRate = $disposableIncome > 0 ? (int) ceil($totalNeeded / $disposableIncome) : null;

        // Target: achieve in 5 years
        $targetMonths = 60;
        $monthlyRequired = $totalNeeded / max($targetMonths, 1);
        $additionalMonthlyNeeded = max(0, $monthlyRequired - $disposableIncome);

        // How many more billable hours?
        $avgRate = $this->revenueService->getAverageEffectiveRate();
        $additionalHoursNeeded = $avgRate > 0 ? (int) ceil($additionalMonthlyNeeded / $avgRate) : null;

        // How many more retainer clients?
        $avgRetainerValue = 3500; // Estimate
        $additionalRetainersNeeded = $additionalMonthlyNeeded > 0 ? (int) ceil($additionalMonthlyNeeded / $avgRetainerValue) : 0;

        return [
            'total_needed' => round($totalNeeded, 2),
            'current_disposable' => round($disposableIncome, 2),
            'months_at_current_rate' => $monthsAtCurrentRate,
            'years_at_current_rate' => $monthsAtCurrentRate ? round($monthsAtCurrentRate / 12, 1) : null,
            'target_months' => $targetMonths,
            'monthly_income_target' => round($monthlyIncome + $additionalMonthlyNeeded, 2),
            'additional_monthly_needed' => round($additionalMonthlyNeeded, 2),
            'additional_hours_needed' => $additionalHoursNeeded,
            'additional_retainers_needed' => $additionalRetainersNeeded,
            'current_effective_rate' => round($avgRate, 2),
        ];
    }

    /**
     * Generate a prioritized action plan based on ALL data.
     *
     * @return array<int, array{priority: int, urgency: string, category: string, action: string, impact: string}>
     */
    protected function generateActionPlan(array $phase, array $debtSummary, array $revenueGap, array $forecast, array $expenseCuts, array $taxSavings, array $velocityNeeded): array
    {
        $actions = [];
        $priority = 1;

        // CRITICAL: Cash shortfall imminent
        if (($forecast['summary']['shortfall_count'] ?? 0) > 0) {
            $shortfall = $forecast['summary']['shortfalls'][0] ?? null;
            if ($shortfall) {
                $actions[] = [
                    'priority' => $priority++,
                    'urgency' => 'critical',
                    'category' => 'cash_flow',
                    'action' => "Cash shortfall of \${$shortfall['shortfall_amount']} projected on {$shortfall['date']}. Accelerate invoice collection or defer non-critical payments immediately.",
                    'impact' => 'Prevents overdraft fees and bounced payments.',
                ];
            }
        }

        // HIGH: Overdue invoices
        $overdueInvoices = Invoice::where('status', 'sent')->where('due_date', '<', now())->get();
        if ($overdueInvoices->count() > 0) {
            $overdueAmount = $overdueInvoices->sum('amount_due');
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'high',
                'category' => 'revenue',
                'action' => 'Collect $'.number_format($overdueAmount, 2)." in overdue invoices ({$overdueInvoices->count()} invoices). Send reminders TODAY.",
                'impact' => 'Immediate cash injection. Every day unpaid costs interest on your debts.',
            ];
        }

        // HIGH: Send draft invoices
        $draftInvoices = Invoice::where('status', 'draft')->get();
        if ($draftInvoices->count() > 0) {
            $draftTotal = $draftInvoices->sum('total');
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'high',
                'category' => 'revenue',
                'action' => 'Send $'.number_format($draftTotal, 2)." in draft invoices ({$draftInvoices->count()} invoices).",
                'impact' => 'Pipeline revenue waiting to be billed.',
            ];
        }

        // HIGH: Revenue gap
        if ($revenueGap['is_deficit']) {
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'high',
                'category' => 'revenue',
                'action' => 'Close $'.number_format($revenueGap['gap'], 2)."/month revenue gap. Target: {$velocityNeeded['additional_retainers_needed']} more retainer client(s) or {$velocityNeeded['additional_hours_needed']} more billable hours/month.",
                'impact' => 'Required to cover all obligations and make progress on debt.',
            ];
        }

        // MEDIUM: Expense cuts
        if ($expenseCuts['total_monthly_savings'] > 100) {
            $topCuts = array_map(fn ($c) => $c['category'], array_slice(array_merge($expenseCuts['personal'], $expenseCuts['business']), 0, 3));
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'medium',
                'category' => 'expenses',
                'action' => 'Cut $'.number_format($expenseCuts['total_monthly_savings'], 2).'/month in expenses ($'.number_format($expenseCuts['total_annual_savings'], 2).'/year). Top cuts: '.implode(', ', $topCuts),
                'impact' => 'Every dollar saved goes directly to debt payoff.',
            ];
        }

        // MEDIUM: Tax optimization
        if ($taxSavings['total_annual_savings'] > 5000) {
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'medium',
                'category' => 'tax',
                'action' => 'Implement tax strategies to save $'.number_format($taxSavings['total_annual_savings'], 2)."/year. Effective rate: {$taxSavings['effective_rate_without']}% -> {$taxSavings['effective_rate_with']}%.",
                'impact' => "That's \$".number_format($taxSavings['monthly_tax_savings'], 2).'/month more for debt payoff.',
            ];
        }

        // MEDIUM: Utilization improvement
        $utilization = $this->revenueService->getUtilizationRate();
        if ($utilization['utilization_rate'] < 70) {
            $potentialRevenue = ($utilization['total_hours'] * 0.7 - $utilization['billable_hours']) * ($velocityNeeded['current_effective_rate'] ?: 150);
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'medium',
                'category' => 'revenue',
                'action' => "Increase billable utilization from {$utilization['utilization_rate']}% to 70%. That's \$".number_format($potentialRevenue, 2).' more revenue at your current rate.',
                'impact' => 'Like giving yourself a raise without adding hours.',
            ];
        }

        // LOW: DSO improvement
        $dso = $this->velocityService->getOverallDso();
        if ($dso > 30) {
            $actions[] = [
                'priority' => $priority++,
                'urgency' => 'low',
                'category' => 'revenue',
                'action' => "Reduce DSO from {$dso} days to under 30. Consider net-15 terms or early-pay discounts.",
                'impact' => 'Faster cash collection improves cash flow and reduces shortfall risk.',
            ];
        }

        return $actions;
    }

    /**
     * Project when each North Star milestone will be achieved.
     *
     * @param  array<string, mixed>|null  $northStar
     * @return array<string, mixed>
     */
    protected function projectNorthStarTimeline(float $totalDebt, float $disposableIncome, array $expenseCuts, array $taxSavings, array $velocityNeeded, ?array $northStar): array
    {
        if (! $northStar) {
            return ['message' => 'Set your North Star goal to see timeline projections.'];
        }

        // Calculate enhanced monthly capacity with all optimizations
        $currentMonthly = $disposableIncome;
        $withExpenseCuts = $currentMonthly + $expenseCuts['total_monthly_savings'];
        $withTaxSavings = $withExpenseCuts + ($taxSavings['monthly_tax_savings'] ?? 0);
        $withRevenueGrowth = $withTaxSavings + ($velocityNeeded['additional_monthly_needed'] ?? 0);

        $northStarCost = (float) ($northStar['total_cost_estimate'] ?? 0);

        $projectDate = function (float $capacity, float $amount): string {
            if ($capacity <= 0 || $amount <= 0) {
                return 'Need more income';
            }
            $months = min((int) ceil($amount / $capacity), 600);

            return now()->addMonths($months)->format('M Y');
        };

        return [
            'scenarios' => [
                [
                    'name' => 'Current trajectory',
                    'monthly_capacity' => round($currentMonthly, 2),
                    'debt_free_date' => $projectDate($currentMonthly, $totalDebt),
                    'north_star_date' => $projectDate($currentMonthly, $totalDebt + $northStarCost),
                ],
                [
                    'name' => 'With expense cuts',
                    'monthly_capacity' => round($withExpenseCuts, 2),
                    'debt_free_date' => $projectDate($withExpenseCuts, $totalDebt),
                    'north_star_date' => $projectDate($withExpenseCuts, $totalDebt + $northStarCost),
                ],
                [
                    'name' => 'With expense cuts + tax optimization',
                    'monthly_capacity' => round($withTaxSavings, 2),
                    'debt_free_date' => $projectDate($withTaxSavings, $totalDebt),
                    'north_star_date' => $projectDate($withTaxSavings, $totalDebt + $northStarCost),
                ],
                [
                    'name' => 'Full optimization (cuts + tax + revenue growth)',
                    'monthly_capacity' => round($withRevenueGrowth, 2),
                    'debt_free_date' => $projectDate($withRevenueGrowth, $totalDebt),
                    'north_star_date' => $projectDate($withRevenueGrowth, $totalDebt + $northStarCost),
                ],
            ],
            'key_insight' => $this->generateKeyInsight($currentMonthly, $withRevenueGrowth, $totalDebt, $northStar),
        ];
    }

    /**
     * Generate a human-readable key insight about the financial trajectory.
     *
     * @param  array<string, mixed>|null  $northStar
     */
    protected function generateKeyInsight(float $current, float $optimized, float $totalDebt, ?array $northStar): string
    {
        if ($current <= 0) {
            return "You're spending more than you earn. The first priority is closing the revenue gap.";
        }

        $currentMonths = (int) ceil($totalDebt / $current);
        $optimizedMonths = $optimized > 0 ? (int) ceil($totalDebt / $optimized) : $currentMonths;
        $monthsSaved = $currentMonths - $optimizedMonths;

        if ($monthsSaved > 12) {
            return 'Full optimization could make you debt-free '.round($monthsSaved / 12, 1).' years sooner -- '.now()->addMonths($optimizedMonths)->format('M Y').' instead of '.now()->addMonths($currentMonths)->format('M Y').'.';
        } elseif ($monthsSaved > 0) {
            return "Full optimization could make you debt-free {$monthsSaved} months sooner.";
        }

        return "Stay the course -- you're making progress toward your goal.";
    }
}
