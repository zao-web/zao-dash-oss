<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashWaterfallAllocation>
 */
class CashWaterfallAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $income = fake()->randomFloat(2, 1000, 20000);

        return [
            'user_id' => User::factory(),
            'trigger_type' => fake()->randomElement(['invoice_paid', 'retainer_received', 'manual', 'simulation']),
            'trigger_description' => fake()->optional()->sentence(),
            'income_amount' => $income,
            'operating_reserve' => round($income * 0.10, 2),
            'tax_reserve' => round($income * 0.30, 2),
            'irs_installment' => round($income * 0.10, 2),
            'debt_payments' => round($income * 0.20, 2),
            'owner_draw' => round($income * 0.25, 2),
            'investment' => round($income * 0.05, 2),
            'allocation_details' => null,
            'is_simulation' => false,
        ];
    }

    public function simulation(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => 'simulation',
            'is_simulation' => true,
        ]);
    }

    public function invoicePaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => 'invoice_paid',
        ]);
    }
}
