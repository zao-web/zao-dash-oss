<?php

namespace Database\Factories;

use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Budget>
 */
class BudgetFactory extends Factory
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
            'category_id' => TransactionCategory::factory(),
            'amount' => fake()->randomFloat(2, 50, 3000),
            'period_type' => 'monthly',
            'effective_from' => now()->startOfMonth(),
            'effective_to' => null,
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'weekly',
            'amount' => fake()->randomFloat(2, 25, 500),
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'yearly',
            'amount' => fake()->randomFloat(2, 1000, 50000),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_from' => now()->subMonths(6)->startOfMonth(),
            'effective_to' => now()->subMonth()->endOfMonth(),
        ]);
    }
}
