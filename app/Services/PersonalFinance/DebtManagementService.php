<?php

namespace App\Services\PersonalFinance;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Collection;

class DebtManagementService
{
    /**
     * Calculate a payoff plan using the specified method.
     * Returns monthly timeline showing when each debt is paid off.
     *
     * @param  string  $method  'avalanche' (highest interest first), 'snowball' (smallest balance first), 'hybrid' (IRS/critical first, then avalanche)
     * @return array{
     *   method: string,
     *   monthly_budget: float,
     *   total_debt: float,
     *   months_to_payoff: int,
     *   payoff_date: string,
     *   total_interest_paid: float,
     *   can_pay_minimums: bool,
     *   total_minimums: float,
     *   payoff_order: array,
     *   timeline: array,
     * }
     */
    public function calculatePayoffPlan(Collection $debts, float $monthlyBudget, string $method = 'hybrid'): array
    {
        $debtData = $debts->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'debt_type' => $d->debt_type,
            'balance' => (float) $d->current_balance,
            'interest_rate' => (float) $d->interest_rate,
            'minimum_payment' => (float) $d->minimum_payment,
            'priority' => $d->priority,
        ])->toArray();

        usort($debtData, fn ($a, $b) => $this->sortDebts($a, $b, $method));

        $timeline = [];
        $month = 0;
        $totalInterestPaid = 0;
        $maxMonths = 360;
        $totalMinimums = array_sum(array_column($debtData, 'minimum_payment'));
        $canPayMinimums = $monthlyBudget >= $totalMinimums;

        $hardshipMode = ! $canPayMinimums;

        while (array_sum(array_column($debtData, 'balance')) > 0.01 && $month < $maxMonths) {
            $month++;
            $available = $monthlyBudget;
            $monthData = ['month' => $month, 'debts' => [], 'total_remaining' => 0];

            // Apply monthly interest
            foreach ($debtData as &$debt) {
                if ($debt['balance'] > 0) {
                    $interest = $debt['balance'] * ($debt['interest_rate'] / 100 / 12);
                    $debt['balance'] += $interest;
                    $totalInterestPaid += $interest;
                }
            }
            unset($debt);

            if ($hardshipMode) {
                // Hardship mode: allocate budget by crisis priority
                // IRS/tax debts first (levy/garnishment risk), then secured, then collections, then unsecured
                $prioritized = $debtData;
                usort($prioritized, fn ($a, $b) => $this->getHardshipPriority($a) <=> $this->getHardshipPriority($b));

                $remaining = $monthlyBudget;
                $payments = [];

                foreach ($prioritized as $pd) {
                    if ($pd['balance'] > 0 && $remaining > 0) {
                        $payment = min($pd['minimum_payment'], $pd['balance'], $remaining);
                        $payments[$pd['id']] = $payment;
                        $remaining -= $payment;
                    }
                }

                // Apply the hardship payments
                foreach ($debtData as &$debt) {
                    if (isset($payments[$debt['id']])) {
                        $debt['balance'] -= $payments[$debt['id']];
                    }
                }
                unset($debt);

                $available = 0;
            } else {
                // Normal mode: Pay minimums first
                foreach ($debtData as &$debt) {
                    if ($debt['balance'] > 0) {
                        $payment = min($debt['minimum_payment'], $debt['balance']);
                        $debt['balance'] -= $payment;
                        $available -= $payment;
                    }
                }
                unset($debt);

                // Apply extra to highest priority debt
                if ($available > 0) {
                    foreach ($debtData as &$debt) {
                        if ($debt['balance'] > 0 && $available > 0) {
                            $extra = min($available, $debt['balance']);
                            $debt['balance'] -= $extra;
                            $available -= $extra;
                            break;
                        }
                    }
                    unset($debt);
                }
            }

            $monthData['total_remaining'] = array_sum(array_column($debtData, 'balance'));
            $monthData['debts'] = array_map(fn ($d) => [
                'id' => $d['id'],
                'name' => $d['name'],
                'balance' => round($d['balance'], 2),
            ], $debtData);

            // Only store key months to keep payload manageable
            if ($month <= 3 || $month % 6 === 0 || $monthData['total_remaining'] < 1) {
                $timeline[] = $monthData;
            }
        }

        $payoffDates = $this->calculatePayoffDates($debts, $monthlyBudget, $method, $maxMonths);

        $result = [
            'method' => $method,
            'monthly_budget' => $monthlyBudget,
            'total_debt' => $debts->sum('current_balance'),
            'months_to_payoff' => $month,
            'payoff_date' => now()->addMonths($month)->format('M Y'),
            'total_interest_paid' => round($totalInterestPaid, 2),
            'can_pay_minimums' => $canPayMinimums,
            'total_minimums' => $totalMinimums,
            'payoff_order' => array_values($payoffDates),
            'timeline' => $timeline,
        ];

        if ($hardshipMode) {
            $shortfall = round($totalMinimums - $monthlyBudget, 2);
            $result['hardship_warning'] = "Your budget (\${$monthlyBudget}/mo) is \${$shortfall} below the combined minimum payments (\${$totalMinimums}/mo). "
                .'Payments are being allocated by crisis priority: IRS/tax debts first, then collections, then unsecured. '
                .'Some debts may grow due to unpaid interest. Contact creditors to negotiate reduced payments or hardship plans.';
            $result['hardship_mode'] = true;
        }

        return $result;
    }

    /**
     * Record a payment against a debt.
     */
    public function recordPayment(Debt $debt, array $data): DebtPayment
    {
        $transactionId = $data['personal_transaction_id'] ?? null;

        // Auto-create a categorized transaction if none linked
        // This ensures debt payments appear in budget tracking
        if (! $transactionId) {
            $account = $debt->personalAccount;
            if ($account) {
                $transaction = PersonalTransaction::create([
                    'personal_account_id' => $account->id,
                    'transaction_date' => $data['payment_date'] ?? now()->toDateString(),
                    'amount' => (float) $data['amount'],
                    'description' => "Debt payment: {$debt->name}",
                    'merchant_name' => $debt->creditor_name,
                    'category_id' => $debt->category_id ?? $this->resolveDebtCategory($debt),
                    'is_recurring' => true,
                    'import_source' => 'manual',
                    'notes' => $data['notes'] ?? null,
                ]);
                $transactionId = $transaction->id;
            }
        }

        $payment = DebtPayment::create([
            'debt_id' => $debt->id,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'amount' => $data['amount'],
            'principal_amount' => $data['principal_amount'] ?? $data['amount'],
            'interest_amount' => $data['interest_amount'] ?? 0,
            'fees_amount' => $data['fees_amount'] ?? 0,
            'payment_method' => $data['payment_method'] ?? null,
            'confirmation_number' => $data['confirmation_number'] ?? null,
            'personal_transaction_id' => $transactionId,
            'notes' => $data['notes'] ?? null,
        ]);

        $principalAmount = (float) ($data['principal_amount'] ?? $data['amount']);

        $debt->update([
            'current_balance' => max(0, $debt->current_balance - $principalAmount),
            'status' => ($debt->current_balance - $principalAmount) <= 0 ? 'paid_off' : $debt->status,
        ]);

        return $payment;
    }

    /**
     * Resolve the best TransactionCategory for a debt based on its type.
     */
    protected function resolveDebtCategory(Debt $debt): ?int
    {
        $categoryName = match ($debt->debt_type) {
            'credit_card' => 'Credit Card Payment',
            'personal_loan' => 'Loan Payment',
            'student_loan' => 'Student Loan',
            'tax_federal', 'tax_state' => 'IRS Installment',
            'collections' => 'Collections',
            default => 'Loan Payment',
        };

        return TransactionCategory::where('name', $categoryName)->value('id');
    }

    /**
     * Get all debts for a user with summary stats.
     *
     * @return array{
     *   debts: Collection,
     *   total_balance: float,
     *   total_minimum_payments: float,
     *   total_original: float,
     *   total_paid: float,
     *   debt_count: int,
     *   paid_off_count: int,
     *   highest_interest: float|null,
     *   tax_debt_total: float,
     *   collections_total: float,
     * }
     */
    public function getDebtSummary(int $userId): array
    {
        $debts = Debt::where('user_id', $userId)
            ->with(['taxObligation', 'collectionsAccount', 'payments' => fn ($q) => $q->orderBy('payment_date', 'desc')->limit(3)])
            ->orderByRaw("CASE WHEN priority = 'critical' THEN 0 WHEN priority = 'high' THEN 1 WHEN priority = 'medium' THEN 2 ELSE 3 END")
            ->get();

        $active = $debts->where('status', '!=', 'paid_off');

        return [
            'debts' => $debts,
            'total_balance' => $active->sum('current_balance'),
            'total_minimum_payments' => $active->sum('minimum_payment'),
            'total_original' => $debts->sum('original_amount'),
            'total_paid' => $debts->sum('original_amount') - $active->sum('current_balance'),
            'debt_count' => $active->count(),
            'paid_off_count' => $debts->where('status', 'paid_off')->count(),
            'highest_interest' => $active->max('interest_rate'),
            'tax_debt_total' => $active->whereIn('debt_type', ['tax_federal', 'tax_state'])->sum('current_balance'),
            'collections_total' => $active->where('debt_type', 'collections')->sum('current_balance'),
        ];
    }

    /**
     * Determine hardship-mode priority for debt ordering.
     * IRS first (levy/garnishment risk), then secured, then collections, then unsecured.
     * Lower number = higher priority.
     */
    protected function getHardshipPriority(array $debt): int
    {
        // IRS/tax debts: highest priority (levy/garnishment risk)
        if (in_array($debt['debt_type'], ['tax_federal', 'tax_state'])) {
            return 0;
        }

        // Critical priority debts
        if ($debt['priority'] === 'critical') {
            return 1;
        }

        // Collections: pay to stop escalation
        if ($debt['debt_type'] === 'collections') {
            return 2;
        }

        // High priority
        if ($debt['priority'] === 'high') {
            return 3;
        }

        // Everything else (unsecured)
        return 4;
    }

    /**
     * Determine hybrid priority for debt ordering.
     * Lower number = higher priority.
     */
    protected function getHybridPriority(array $debt): int
    {
        if (in_array($debt['debt_type'], ['tax_federal', 'tax_state'])) {
            return 0;
        }

        if ($debt['priority'] === 'critical') {
            return 1;
        }

        if ($debt['priority'] === 'high') {
            return 2;
        }

        if ($debt['debt_type'] === 'collections') {
            return 3;
        }

        return 4;
    }

    /**
     * Sort debts based on the chosen payoff method.
     */
    protected function sortDebts(array $a, array $b, string $method): int
    {
        if ($method === 'hybrid') {
            $aPriority = $this->getHybridPriority($a);
            $bPriority = $this->getHybridPriority($b);

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            return $b['interest_rate'] <=> $a['interest_rate'];
        }

        return match ($method) {
            'avalanche' => $b['interest_rate'] <=> $a['interest_rate'],
            'snowball' => $a['balance'] <=> $b['balance'],
            default => 0,
        };
    }

    /**
     * Run a separate simulation to determine payoff date per debt.
     *
     * @return array<int, array{name: string, month: int, date: string}>
     */
    protected function calculatePayoffDates(Collection $debts, float $monthlyBudget, string $method, int $maxMonths): array
    {
        $tempDebts = $debts->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'balance' => (float) $d->current_balance,
            'interest_rate' => (float) $d->interest_rate,
            'minimum_payment' => (float) $d->minimum_payment,
            'priority' => $d->priority,
            'debt_type' => $d->debt_type,
        ])->toArray();

        usort($tempDebts, fn ($a, $b) => $this->sortDebts($a, $b, $method));

        $payoffDates = [];
        $month = 0;

        while (array_sum(array_column($tempDebts, 'balance')) > 0.01 && $month < $maxMonths) {
            $month++;
            $available = $monthlyBudget;

            // Apply interest
            foreach ($tempDebts as &$td) {
                if ($td['balance'] > 0) {
                    $td['balance'] += $td['balance'] * ($td['interest_rate'] / 100 / 12);
                }
            }
            unset($td);

            // Pay minimums
            foreach ($tempDebts as &$td) {
                if ($td['balance'] > 0) {
                    $payment = min($td['minimum_payment'], $td['balance']);
                    $td['balance'] -= $payment;
                    $available -= $payment;
                }
            }
            unset($td);

            // Extra to top priority
            if ($available > 0) {
                foreach ($tempDebts as &$td) {
                    if ($td['balance'] > 0 && $available > 0) {
                        $extra = min($available, $td['balance']);
                        $td['balance'] -= $extra;
                        $available -= $extra;
                        break;
                    }
                }
                unset($td);
            }

            // Record payoff dates
            foreach ($tempDebts as $td) {
                if ($td['balance'] <= 0.01 && ! isset($payoffDates[$td['id']])) {
                    $payoffDates[$td['id']] = [
                        'name' => $td['name'],
                        'month' => $month,
                        'date' => now()->addMonths($month)->format('M Y'),
                    ];
                }
            }
        }

        return $payoffDates;
    }
}
