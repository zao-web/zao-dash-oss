<?php

namespace App\Services\PersonalFinance;

use App\Models\Debt;
use App\Models\FinancialSnapshot;
use App\Models\PersonalAccount;

class FinancialPhaseService
{
    const PHASE_CRISIS = 'crisis';

    const PHASE_STABILIZING = 'stabilizing';

    const PHASE_ACCELERATING = 'accelerating';

    const PHASE_BUILDING = 'building';

    const PHASE_COMPOUNDING = 'compounding';

    /**
     * Detect the user's current financial phase based on their data.
     *
     * @return array{phase: string, phase_number: int, phase_label: string, description: string, next_milestone: string, metrics: array<string, float>}
     */
    public function detectPhase(int $userId): array
    {
        $debts = Debt::where('user_id', $userId)->where('status', '!=', 'paid_off');
        $totalDebt = (float) $debts->sum('current_balance');
        $totalMinimums = (float) $debts->sum('minimum_payment');
        $consumerDebt = (float) (clone $debts)->whereIn('debt_type', ['credit_card', 'personal_loan', 'medical'])->sum('current_balance');
        $taxDebt = (float) (clone $debts)->whereIn('debt_type', ['tax_federal', 'tax_state'])->sum('current_balance');

        $cashAccounts = PersonalAccount::where('user_id', $userId)
            ->where('is_closed', false)
            ->whereIn('account_type', ['checking', 'savings']);
        $totalCash = (float) $cashAccounts->sum('current_balance');

        $snapshot = FinancialSnapshot::latest('monthly');
        $monthlyIncome = (float) ($snapshot?->total_income ?? 0);

        $investments = (float) PersonalAccount::where('user_id', $userId)
            ->where('account_type', 'investment')
            ->sum('current_balance');

        $netWorth = $totalCash + $investments - $totalDebt;

        // Phase detection logic
        if ($totalMinimums > $monthlyIncome * 0.5 || $totalCash < 0) {
            $phase = self::PHASE_CRISIS;
        } elseif ($consumerDebt > 0) {
            $phase = self::PHASE_STABILIZING;
        } elseif ($taxDebt > 0 || Debt::where('user_id', $userId)->where('debt_type', 'collections')->where('status', 'active')->exists()) {
            $phase = self::PHASE_ACCELERATING;
        } elseif ($investments < $monthlyIncome * 3) {
            $phase = self::PHASE_BUILDING;
        } else {
            $phase = self::PHASE_COMPOUNDING;
        }

        $phases = [self::PHASE_CRISIS, self::PHASE_STABILIZING, self::PHASE_ACCELERATING, self::PHASE_BUILDING, self::PHASE_COMPOUNDING];

        return [
            'phase' => $phase,
            'phase_number' => array_search($phase, $phases) + 1,
            'phase_label' => ucfirst($phase),
            'description' => $this->getPhaseDescription($phase),
            'next_milestone' => $this->getNextMilestone($phase, $userId),
            'metrics' => [
                'net_worth' => round($netWorth, 2),
                'total_debt' => round($totalDebt, 2),
                'total_cash' => round($totalCash, 2),
                'consumer_debt' => round($consumerDebt, 2),
                'tax_debt' => round($taxDebt, 2),
                'investments' => round($investments, 2),
            ],
        ];
    }

    /**
     * Get a human-readable description of the financial phase.
     */
    protected function getPhaseDescription(string $phase): string
    {
        return match ($phase) {
            self::PHASE_CRISIS => 'Debt obligations exceed 50% of income or cash is negative. Focus on survival: cut expenses, negotiate with creditors, stabilize cash flow.',
            self::PHASE_STABILIZING => 'Consumer debt (credit cards, personal loans, medical) still exists. Focus on eliminating high-interest consumer debt using avalanche or snowball method.',
            self::PHASE_ACCELERATING => 'Tax debt or collections remain. Focus on resolving IRS obligations, settling collections strategically, and building a small emergency fund.',
            self::PHASE_BUILDING => 'No consumer or tax debt. Focus on building investment portfolio, maximizing retirement contributions, and establishing 3-6 months emergency fund.',
            self::PHASE_COMPOUNDING => 'Investments exceed 3 months of income. Focus on tax optimization, real estate strategies, wealth preservation, and legacy planning.',
        };
    }

    /**
     * Get the next milestone to reach based on current phase.
     */
    protected function getNextMilestone(string $phase, int $userId): string
    {
        return match ($phase) {
            self::PHASE_CRISIS => 'Get debt payments below 50% of monthly income and build $500 emergency buffer.',
            self::PHASE_STABILIZING => 'Pay off all consumer debt (credit cards, personal loans, medical bills).',
            self::PHASE_ACCELERATING => 'Resolve all tax obligations and collections. Build $1,000 emergency fund.',
            self::PHASE_BUILDING => 'Accumulate investments worth 3x monthly income. Max out retirement contributions.',
            self::PHASE_COMPOUNDING => 'Optimize tax strategies. Target investments of 12x monthly income (1 year runway).',
        };
    }
}
