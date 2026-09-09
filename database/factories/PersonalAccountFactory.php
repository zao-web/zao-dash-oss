<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonalAccount>
 */
class PersonalAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $accountType = fake()->randomElement(['checking', 'savings', 'credit_card', 'loan', 'collections', 'tax_debt', 'investment', 'other']);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Primary Checking', 'Savings Account', 'Visa Platinum', 'Auto Loan', 'Roth IRA', 'Emergency Fund']),
            'institution_name' => fake()->randomElement(['Chase', 'Bank of America', 'Wells Fargo', 'Capital One', 'Ally Bank', 'Fidelity']),
            'account_type' => $accountType,
            'account_subtype' => null,
            'current_balance' => fake()->randomFloat(2, -5000, 50000),
            'available_balance' => fake()->optional()->randomFloat(2, 0, 50000),
            'credit_limit' => $accountType === 'credit_card' ? fake()->randomFloat(2, 1000, 25000) : null,
            'interest_rate' => in_array($accountType, ['credit_card', 'loan', 'savings']) ? fake()->randomFloat(2, 0.5, 29.99) : null,
            'currency_code' => 'USD',
            'is_business' => false,
            'is_closed' => false,
            'plaid_account_id' => null,
            'plaid_item_id' => null,
            'last_synced_at' => null,
            'metadata' => null,
        ];
    }

    public function checking(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Primary Checking',
            'account_type' => 'checking',
            'current_balance' => fake()->randomFloat(2, 500, 15000),
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Visa Platinum', 'Mastercard Rewards', 'Amex Gold']),
            'account_type' => 'credit_card',
            'current_balance' => fake()->randomFloat(2, -10000, 0),
            'credit_limit' => fake()->randomFloat(2, 5000, 25000),
            'interest_rate' => fake()->randomFloat(2, 15.99, 29.99),
        ]);
    }

    public function savings(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Savings Account',
            'account_type' => 'savings',
            'current_balance' => fake()->randomFloat(2, 1000, 50000),
            'interest_rate' => fake()->randomFloat(2, 0.5, 5.25),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_closed' => true,
        ]);
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_business' => true,
            'name' => 'Business Checking',
        ]);
    }
}
