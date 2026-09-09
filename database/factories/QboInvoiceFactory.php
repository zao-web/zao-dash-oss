<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QboInvoice>
 */
class QboInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalAmount = $this->faker->randomFloat(2, 100, 10000);
        $balance = $this->faker->randomFloat(2, 0, $totalAmount);

        return [
            'quickbooks_connection_id' => \App\Models\QuickBooksConnection::factory(),
            'qbo_invoice_id' => $this->faker->unique()->numerify('####'),
            'doc_number' => $this->faker->unique()->numerify('INV-####'),
            'txn_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'due_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'total_amount' => $totalAmount,
            'balance' => $balance,
            'status' => $this->faker->randomElement(['Open', 'Paid', 'Pending']),
            'customer_name' => $this->faker->company(),
            'line_items' => [],
            'client_id' => null,
            'harvest_invoice_id' => null,
            'synced_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
