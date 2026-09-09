<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TransactionCategory>
 */
class TransactionCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'parent_id' => null,
            'name' => fake()->randomElement(['Groceries', 'Rent', 'Utilities', 'Entertainment', 'Transportation', 'Healthcare', 'Dining Out']),
            'type' => fake()->randomElement(['income', 'expense', 'transfer', 'tax_payment', 'debt_payment']),
            'icon' => null,
            'color' => fake()->optional()->hexColor(),
            'is_system' => false,
            'tax_category' => null,
            'budget_trackable' => true,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
            'user_id' => null,
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'expense',
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'income',
            'name' => fake()->randomElement(['Salary', 'Freelance Income', 'Dividends', 'Rental Income']),
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'transfer',
            'budget_trackable' => false,
        ]);
    }
}
