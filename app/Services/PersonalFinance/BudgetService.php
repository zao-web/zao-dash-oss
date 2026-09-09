<?php

namespace App\Services\PersonalFinance;

use App\Models\Budget;
use App\Models\Debt;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use Carbon\Carbon;

class BudgetService
{
    /**
     * Get budget status for a user for a given month.
     * Returns each budgeted category with target, actual spending, and remaining.
     *
     * @return array{
     *     month: string,
     *     month_key: string,
     *     categories: array<int, array{
     *         budget_id: int,
     *         category_id: int,
     *         category_name: string,
     *         category_type: string,
     *         parent_category_name: string|null,
     *         target: float,
     *         spent: float,
     *         remaining: float,
     *         percent_used: int,
     *         status: string,
     *     }>,
     *     total_budgeted: float,
     *     total_spent: float,
     *     total_remaining: float,
     *     uncategorized_spending: float,
     *     overall_percent: int,
     * }
     */
    public function getMonthlyBudgetStatus(int $userId, ?Carbon $month = null): array
    {
        $month = $month ?? now();
        $startOfMonth = $month->copy()->startOfMonth();
        $endOfMonth = $month->copy()->endOfMonth();

        $budgets = Budget::where('user_id', $userId)
            ->where('effective_from', '<=', $endOfMonth)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $startOfMonth))
            ->with('category.parent')
            ->get();

        $spending = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->visibleOnLife()
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('amount', '>', 0)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as total_spent')
            ->groupBy('category_id')
            ->pluck('total_spent', 'category_id');

        $categorySpending = [];

        foreach ($budgets as $budget) {
            $categoryId = $budget->category_id;
            $directSpent = (float) ($spending[$categoryId] ?? 0);

            $childIds = TransactionCategory::where('parent_id', $categoryId)->pluck('id');
            $childSpent = $childIds->sum(fn ($id) => (float) ($spending[$id] ?? 0));

            $totalSpent = $directSpent + $childSpent;

            $targetAmount = match ($budget->period_type) {
                'weekly' => (float) $budget->amount * 4.33,
                default => (float) $budget->amount,
            };

            $categorySpending[] = [
                'budget_id' => $budget->id,
                'category_id' => $categoryId,
                'category_name' => $budget->category->name,
                'category_type' => $budget->category->type,
                'parent_category_name' => $budget->category->parent?->name,
                'target' => round($targetAmount, 2),
                'spent' => round($totalSpent, 2),
                'remaining' => round($targetAmount - $totalSpent, 2),
                'percent_used' => $targetAmount > 0 ? round(($totalSpent / $targetAmount) * 100) : 0,
                'status' => $this->getBudgetStatus($totalSpent, $targetAmount),
            ];
        }

        // Include active debts with minimum payments as implicit budget lines
        // if they aren't already represented via an explicit Budget entry
        $budgetedCategoryIds = $budgets->pluck('category_id')->toArray();
        $activeDebts = Debt::where('user_id', $userId)
            ->active()
            ->where('minimum_payment', '>', 0)
            ->get();

        foreach ($activeDebts as $debt) {
            $debtCategoryId = $debt->category_id ?? $this->resolveDebtCategoryId($debt);

            if (! $debtCategoryId || in_array($debtCategoryId, $budgetedCategoryIds)) {
                continue;
            }

            $directSpent = (float) ($spending[$debtCategoryId] ?? 0);
            $childIds = TransactionCategory::where('parent_id', $debtCategoryId)->pluck('id');
            $childSpent = $childIds->sum(fn ($id) => (float) ($spending[$id] ?? 0));
            $totalDebtSpent = $directSpent + $childSpent;
            $targetAmount = (float) $debt->minimum_payment;

            $categorySpending[] = [
                'budget_id' => 0,
                'category_id' => $debtCategoryId,
                'category_name' => $debt->name,
                'category_type' => 'debt_payment',
                'parent_category_name' => 'Debt Payments',
                'target' => round($targetAmount, 2),
                'spent' => round($totalDebtSpent, 2),
                'remaining' => round($targetAmount - $totalDebtSpent, 2),
                'percent_used' => $targetAmount > 0 ? round(($totalDebtSpent / $targetAmount) * 100) : 0,
                'status' => $this->getBudgetStatus($totalDebtSpent, $targetAmount),
            ];

            $budgetedCategoryIds[] = $debtCategoryId;
        }

        $uncategorized = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->visibleOnLife()
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('amount', '>', 0)
            ->whereNull('category_id')
            ->sum('amount');

        $totalBudgeted = collect($categorySpending)->sum('target');
        $totalSpent = collect($categorySpending)->sum('spent') + (float) $uncategorized;

        return [
            'month' => $month->format('F Y'),
            'month_key' => $month->format('Y-m'),
            'categories' => $categorySpending,
            'total_budgeted' => round((float) $totalBudgeted, 2),
            'total_spent' => round($totalSpent, 2),
            'total_remaining' => round((float) $totalBudgeted - $totalSpent, 2),
            'uncategorized_spending' => round((float) $uncategorized, 2),
            'overall_percent' => $totalBudgeted > 0 ? (int) round(($totalSpent / (float) $totalBudgeted) * 100) : 0,
        ];
    }

    protected function getBudgetStatus(float $spent, float $target): string
    {
        if ($target <= 0) {
            return 'no_budget';
        }

        $percent = ($spent / $target) * 100;

        if ($percent >= 100) {
            return 'over';
        }

        if ($percent >= 80) {
            return 'warning';
        }

        return 'on_track';
    }

    /**
     * Resolve the best TransactionCategory ID for a debt based on its type.
     */
    protected function resolveDebtCategoryId(Debt $debt): ?int
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
     * Get spending breakdown by top-level category for a month.
     *
     * @return array<int, array{category: string, total: float, count: int, transactions: array}>
     */
    public function getSpendingBreakdown(int $userId, ?Carbon $month = null): array
    {
        $month = $month ?? now();
        $startOfMonth = $month->copy()->startOfMonth();
        $endOfMonth = $month->copy()->endOfMonth();

        return PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->visibleOnLife()
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('amount', '>', 0)
            ->whereNotNull('category_id')
            ->with('category.parent')
            ->get()
            ->groupBy(fn ($t) => $t->category->parent_id ? $t->category->parent->name : $t->category->name)
            ->map(fn ($group, $name) => [
                'category' => $name,
                'total' => round($group->sum('amount'), 2),
                'count' => $group->count(),
                'transactions' => $group->sortByDesc('transaction_date')->values()->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => $t->transaction_date->format('M j'),
                    'description' => $t->description,
                    'merchant' => $t->merchant_name,
                    'amount' => round((float) $t->amount, 2),
                    'category_id' => $t->category_id,
                    'category_name' => $t->category->name,
                ])->toArray(),
            ])
            ->sortByDesc('total')
            ->values()
            ->toArray();
    }
}
