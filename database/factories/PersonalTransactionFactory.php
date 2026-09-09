<?php

namespace Database\Factories;

use App\Models\PersonalAccount;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PersonalTransaction>
 */
class PersonalTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $description = fake()->randomElement([
            'Walmart Grocery', 'Amazon.com', 'Shell Gas Station', 'Netflix Subscription',
            'Starbucks Coffee', 'Target', 'Costco Wholesale', 'Uber Ride',
            'Electric Bill', 'Water Utility', 'Internet Service', 'Phone Bill',
        ]);

        return [
            'personal_account_id' => PersonalAccount::factory(),
            'transaction_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'amount' => fake()->randomFloat(2, -500, 5000),
            'description' => $description,
            'original_description' => $description,
            'category_id' => null,
            'merchant_name' => fake()->optional()->company(),
            'is_recurring' => fake()->boolean(20),
            'is_tax_deductible' => fake()->boolean(10),
            'plaid_transaction_id' => null,
            'import_source' => 'manual',
            'import_batch_id' => null,
            'notes' => null,
            'tags' => null,
        ];
    }

    public function withCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => TransactionCategory::factory(),
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => fake()->randomFloat(2, -500, -5),
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => fake()->randomFloat(2, 100, 10000),
            'description' => fake()->randomElement(['Direct Deposit', 'Payroll', 'Client Payment', 'Freelance Invoice']),
        ]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recurring' => true,
        ]);
    }

    public function imported(string $source = 'csv'): static
    {
        return $this->state(fn (array $attributes) => [
            'import_source' => $source,
            'import_batch_id' => fake()->uuid(),
        ]);
    }
}
