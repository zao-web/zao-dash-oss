<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialGoal>
 */
class FinancialGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $targetAmount = fake()->randomFloat(2, 1000, 100000);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Emergency Fund', '401k Match', 'Pay Off Credit Cards', 'Down Payment', 'Vacation Fund', 'New Car Fund']),
            'goal_type' => fake()->randomElement(['emergency_fund', 'debt_payoff', 'retirement', 'investing', 'savings', 'custom']),
            'target_amount' => $targetAmount,
            'current_amount' => fake()->randomFloat(2, 0, $targetAmount),
            'target_date' => fake()->optional()->dateTimeBetween('+1 month', '+5 years'),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'status' => 'active',
            'linked_account_id' => null,
            'notes' => null,
        ];
    }

    public function achieved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'achieved',
            'current_amount' => $attributes['target_amount'],
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }

    public function emergencyFund(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Emergency Fund',
            'goal_type' => 'emergency_fund',
            'priority' => 'high',
        ]);
    }

    public function debtPayoff(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Debt Payoff',
            'goal_type' => 'debt_payoff',
            'priority' => 'high',
        ]);
    }
}
