<?php

namespace Database\Factories;

use App\Models\HarvestInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HarvestInvoice>
 */
class HarvestInvoiceFactory extends Factory
{
    protected $model = HarvestInvoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 500, 10000);

        return [
            'harvest_id' => $this->faker->unique()->randomNumber(8),
            'harvest_client_id' => $this->faker->unique()->randomNumber(8),
            'client_id' => \App\Models\Client::factory(),
            'number' => 'INV-'.$this->faker->unique()->numberBetween(1000, 9999),
            'state' => 'draft',
            'amount' => $amount,
            'due_amount' => $amount,
            'issue_date' => now(),
            'due_date' => now()->addDays(30),
            'sent_at' => null,
            'paid_at' => null,
        ];
    }

    /**
     * Indicate that the invoice has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'sent',
            'sent_at' => now()->subDays(5),
        ]);
    }

    /**
     * Indicate that the invoice has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'paid',
            'due_amount' => 0,
            'sent_at' => now()->subDays(10),
            'paid_at' => now()->subDays(2),
        ]);
    }

    /**
     * Indicate that the invoice is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'sent',
            'due_date' => now()->subDays(15),
            'sent_at' => now()->subDays(45),
        ]);
    }
}
