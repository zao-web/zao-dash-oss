<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialSnapshot>
 */
class FinancialSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalIncome = $this->faker->randomFloat(2, 10000, 100000);
        $totalExpenses = $this->faker->randomFloat(2, 5000, $totalIncome * 0.8);
        $netProfit = $totalIncome - $totalExpenses;

        return [
            'qbo_connection_id' => \App\Models\QuickBooksConnection::factory(),
            'period_type' => $this->faker->randomElement(['daily', 'weekly', 'monthly']),
            'period_start' => $this->faker->dateTimeBetween('-1 month', '-1 week'),
            'period_end' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'accounts_receivable' => $this->faker->randomFloat(2, 0, 50000),
            'accounts_payable' => $this->faker->randomFloat(2, 0, 30000),
            'cash_on_hand' => $this->faker->randomFloat(2, 1000, 100000),
            'top_expense_categories' => [],
            'top_income_sources' => [],
            'insights' => [],
        ];
    }
}
