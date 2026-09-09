<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QboTransaction>
 */
class QboTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quickbooks_connection_id' => \App\Models\QuickBooksConnection::factory(),
            'qbo_transaction_id' => $this->faker->unique()->numerify('####'),
            'txn_type' => $this->faker->randomElement(['Invoice', 'Payment', 'Expense', 'Bill', 'Deposit', 'SalesReceipt']),
            'txn_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'description' => $this->faker->sentence(),
            'account_id' => null,
            'client_id' => null,
            'project_id' => null,
            'is_reconciled' => $this->faker->boolean(60),
            'synced_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
