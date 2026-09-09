<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashFlowForecast>
 */
class CashFlowForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'forecast_date' => fake()->dateTimeBetween('now', '+6 months'),
            'type' => fake()->randomElement(['income', 'expense', 'debt_payment', 'tax_payment', 'transfer']),
            'description' => fake()->randomElement(['Payroll Direct Deposit', 'Rent Payment', 'IRS Installment', 'Electric Bill', 'Insurance Premium']),
            'projected_amount' => fake()->randomFloat(2, 50, 10000),
            'actual_amount' => null,
            'is_recurring' => fake()->boolean(60),
            'recurrence_rule' => null,
            'source' => 'manual',
            'confidence' => fake()->randomElement(['estimated', 'confirmed', 'historical']),
            'personal_account_id' => null,
            'debt_id' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
            'description' => fake()->randomElement(['Payroll', 'Client Payment', 'Freelance Invoice']),
            'projected_amount' => fake()->randomFloat(2, 2000, 15000),
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
            'description' => fake()->randomElement(['Rent', 'Utilities', 'Insurance', 'Groceries']),
            'projected_amount' => fake()->randomFloat(2, 50, 3000),
        ]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring' => true,
            'recurrence_rule' => 'FREQ=MONTHLY;INTERVAL=1',
        ]);
    }

    public function reconciled(): static
    {
        return $this->state(fn (array $attributes) => [
            'forecast_date' => fake()->dateTimeBetween('-3 months', '-1 day'),
            'actual_amount' => fake()->randomFloat(2, 50, 10000),
        ]);
    }
}
