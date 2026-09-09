<?php

namespace App\Services\PersonalFinance;

use App\Models\CashWaterfallAllocation;
use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\SinkingFund;
use App\Models\TaxObligation;

class CashWaterfallService
{
    public function __construct(
        protected DebtManagementService $debtService,
    ) {}

    /**
     * Allocate income through the waterfall priority system.
     *
     * Priority: Operating Reserve -> Tax Reserve -> IRS Installment -> Debt Payments -> Sinking Funds -> Owner's Draw -> Investment
     *
     * @return array{
     *     allocation: CashWaterfallAllocation,
     *     breakdown: array{
     *         operating_reserve: array{amount: float, description: string},
     *         tax_reserve: array{amount: float, description: string},
     *         irs_installment: array{amount: float, description: string},
     *         debt_payments: array{amount: float, description: string},
     *         sinking_funds: array{amount: float, description: string},
     *         owner_draw: array{amount: float, description: string},
     *         investment: array{amount: float, description: string},
     *     },
     *     remaining: float,
     * }
     */
    public function allocate(int $userId, float $incomeAmount, string $triggerType, ?string $triggerDescription = null): array
    {
        $remaining = $incomeAmount;
        $breakdown = [];

        // 1. Operating Reserve (10% of income, capped at 1 month expenses)
        $snapshot = FinancialSnapshot::orderBy('created_at', 'desc')->first();
        $monthlyExpenses = (float) ($snapshot?->total_expenses ?? 0);
        $operatingReserveTarget = min($incomeAmount * 0.10, max($monthlyExpenses * 0.10, 100));
        $operatingReserve = min($remaining, $operatingReserveTarget);
        $remaining -= $operatingReserve;
        $breakdown['operating_reserve'] = [
            'amount' => round($operatingReserve, 2),
            'description' => 'Operating reserve (10% target)',
        ];

        // 2. Tax Reserve (30% of income for self-employment taxes)
        $taxReserveRate = 0.30;
        $taxReserve = min($remaining, $incomeAmount * $taxReserveRate);
        $remaining -= $taxReserve;
        $breakdown['tax_reserve'] = [
            'amount' => round($taxReserve, 2),
            'description' => 'Tax reserve (30% for quarterly estimates)',
        ];

        // 3. IRS Installment (monthly installment agreement amount)
        $irsInstallment = TaxObligation::whereHas('debt', fn ($q) => $q->where('user_id', $userId)->where('status', 'active'))
            ->whereNotNull('installment_monthly')
            ->sum('installment_monthly');
        $irsPayment = min($remaining, (float) $irsInstallment);
        $remaining -= $irsPayment;
        $breakdown['irs_installment'] = [
            'amount' => round($irsPayment, 2),
            'description' => $irsInstallment > 0
                ? 'IRS installment agreement ($'.number_format((float) $irsInstallment, 2).'/mo)'
                : 'No active IRS installment',
        ];

        // 4. Debt Payments (minimum payments for all active debts, excluding IRS)
        $debts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->whereNotIn('debt_type', ['tax_federal', 'tax_state'])
            ->get();
        $totalMinimums = (float) $debts->sum('minimum_payment');
        $debtPayment = min($remaining, $totalMinimums);
        $remaining -= $debtPayment;
        $breakdown['debt_payments'] = [
            'amount' => round($debtPayment, 2),
            'description' => $debts->count().' active debts ($'.number_format($totalMinimums, 2).'/mo minimums)',
        ];

        // 5. Sinking Funds (monthly contributions to planned obligations)
        $sinkingFunds = SinkingFund::where('user_id', $userId)
            ->whereIn('status', ['saving', 'planning'])
            ->get();
        $totalSinkingContributions = (float) $sinkingFunds->sum('monthly_contribution');
        $sinkingPayment = min($remaining, $totalSinkingContributions);
        $remaining -= $sinkingPayment;
        $breakdown['sinking_funds'] = [
            'amount' => round($sinkingPayment, 2),
            'description' => $sinkingFunds->count().' sinking fund'.($sinkingFunds->count() !== 1 ? 's' : '').' ($'.number_format($totalSinkingContributions, 2).'/mo contributions)',
        ];

        // 6. Owner's Draw (50% of remaining)
        $ownerDraw = round($remaining * 0.50, 2);
        $remaining -= $ownerDraw;
        $breakdown['owner_draw'] = [
            'amount' => round($ownerDraw, 2),
            'description' => "Owner's draw (50% of remaining after obligations)",
        ];

        // 7. Investment (everything left)
        $investment = round($remaining, 2);
        $remaining = 0;
        $breakdown['investment'] = [
            'amount' => $investment,
            'description' => 'Investment/savings (remainder)',
        ];

        $allocation = CashWaterfallAllocation::create([
            'user_id' => $userId,
            'trigger_type' => $triggerType,
            'trigger_description' => $triggerDescription,
            'income_amount' => $incomeAmount,
            'operating_reserve' => $breakdown['operating_reserve']['amount'],
            'tax_reserve' => $breakdown['tax_reserve']['amount'],
            'irs_installment' => $breakdown['irs_installment']['amount'],
            'debt_payments' => $breakdown['debt_payments']['amount'],
            'sinking_funds' => $breakdown['sinking_funds']['amount'],
            'owner_draw' => $breakdown['owner_draw']['amount'],
            'investment' => $breakdown['investment']['amount'],
            'allocation_details' => $breakdown,
            'is_simulation' => $triggerType === 'simulation',
        ]);

        return [
            'allocation' => $allocation,
            'breakdown' => $breakdown,
            'remaining' => round($remaining, 2),
        ];
    }

    /**
     * Simulate the impact of new revenue on debt payoff timeline.
     *
     * @return array{
     *     waterfall: array,
     *     debt_impact: array{
     *         current_monthly_budget: float,
     *         new_monthly_budget: float,
     *         current_payoff_months: int,
     *         new_payoff_months: int,
     *         months_saved: int,
     *         interest_saved: float,
     *     },
     *     summary: string,
     * }
     */
    public function simulateRevenueImpact(int $userId, float $newRevenue, string $description = 'Revenue simulation'): array
    {
        // Run the waterfall allocation as a simulation
        $waterfall = $this->allocate($userId, $newRevenue, 'simulation', $description);

        // Get current debt situation
        $debts = Debt::where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        if ($debts->isEmpty()) {
            return [
                'waterfall' => $waterfall,
                'debt_impact' => [
                    'current_monthly_budget' => 0,
                    'new_monthly_budget' => 0,
                    'current_payoff_months' => 0,
                    'new_payoff_months' => 0,
                    'months_saved' => 0,
                    'interest_saved' => 0,
                ],
                'summary' => 'No active debts. All additional revenue goes to savings and investment.',
            ];
        }

        $currentMinimums = (float) $debts->sum('minimum_payment');

        // Current payoff plan
        $currentPlan = $this->debtService->calculatePayoffPlan($debts, $currentMinimums, 'hybrid');

        // New payoff plan with additional debt payment budget from waterfall
        $additionalDebtBudget = $waterfall['breakdown']['debt_payments']['amount'];
        $newMonthlyBudget = $currentMinimums + $additionalDebtBudget;
        $newPlan = $this->debtService->calculatePayoffPlan($debts, $newMonthlyBudget, 'hybrid');

        $monthsSaved = $currentPlan['months_to_payoff'] - $newPlan['months_to_payoff'];
        $interestSaved = $currentPlan['total_interest_paid'] - $newPlan['total_interest_paid'];

        return [
            'waterfall' => $waterfall,
            'debt_impact' => [
                'current_monthly_budget' => round($currentMinimums, 2),
                'new_monthly_budget' => round($newMonthlyBudget, 2),
                'current_payoff_months' => $currentPlan['months_to_payoff'],
                'new_payoff_months' => $newPlan['months_to_payoff'],
                'months_saved' => max(0, $monthsSaved),
                'interest_saved' => round(max(0, $interestSaved), 2),
            ],
            'summary' => $monthsSaved > 0
                ? 'Adding $'.number_format($newRevenue, 2).'/mo would save '.$monthsSaved.' months and $'.number_format(max(0, $interestSaved), 2).' in interest. Debt-free by '.$newPlan['payoff_date'].' instead of '.$currentPlan['payoff_date'].'.'
                : 'Additional revenue of $'.number_format($newRevenue, 2).' allocated through waterfall. Debt payoff timeline: '.$newPlan['payoff_date'].'.',
        ];
    }
}
