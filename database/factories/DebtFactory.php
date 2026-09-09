<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Debt>
 */
class DebtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originalAmount = fake()->randomFloat(2, 1000, 50000);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Chase Visa', 'Student Loan', 'IRS 2022', 'Medical Bill', 'Capital One Card']),
            'debt_type' => fake()->randomElement(['credit_card', 'personal_loan', 'student_loan', 'tax_federal', 'tax_state', 'collections', 'medical', 'other']),
            'creditor_name' => fake()->company(),
            'original_amount' => $originalAmount,
            'current_balance' => fake()->randomFloat(2, 100, $originalAmount),
            'interest_rate' => fake()->randomFloat(2, 0, 29.99),
            'minimum_payment' => fake()->randomFloat(2, 25, 500),
            'payment_due_day' => fake()->numberBetween(1, 28),
            'personal_account_id' => null,
            'status' => 'active',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'notes' => null,
            'metadata' => null,
        ];
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'debt_type' => 'credit_card',
            'interest_rate' => fake()->randomFloat(2, 15.99, 29.99),
        ]);
    }

    public function taxFederal(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'IRS Federal Tax Debt',
            'debt_type' => 'tax_federal',
            'creditor_name' => 'Internal Revenue Service',
            'interest_rate' => fake()->randomFloat(2, 3, 8),
        ]);
    }

    public function collections(): static
    {
        return $this->state(fn (array $attributes) => [
            'debt_type' => 'collections',
            'priority' => 'high',
        ]);
    }

    public function paidOff(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_balance' => 0,
            'status' => 'paid_off',
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }
}
