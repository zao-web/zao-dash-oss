<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $taxRate = fake()->randomElement([0, 5, 7.5, 10]);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $taxAmount;

        return [
            'client_id' => Client::factory(),
            'number' => 'INV-'.fake()->unique()->numerify('####'),
            'subject' => fake()->sentence(3),
            'notes' => fake()->optional()->paragraph(),
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => 0,
            'amount_due' => $total,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'USD',
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PAID,
            'amount_paid' => $attributes['total'],
            'amount_due' => 0,
            'paid_at' => now(),
        ]);
    }

    public function withPayPal(): static
    {
        return $this->state(fn (array $attributes) => [
            'paypal_invoice_id' => 'INV2-'.fake()->regexify('[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}'),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_OVERDUE,
            'due_date' => now()->subDays(7),
        ]);
    }
}
