<?php

namespace Database\Factories;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 50, 2000);
        $interestAmount = round($amount * fake()->randomFloat(2, 0.05, 0.3), 2);
        $principalAmount = round($amount - $interestAmount, 2);

        return [
            'debt_id' => Debt::factory(),
            'payment_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'amount' => $amount,
            'principal_amount' => $principalAmount,
            'interest_amount' => $interestAmount,
            'fees_amount' => 0,
            'payment_method' => fake()->randomElement(['bank_transfer', 'check', 'auto_pay', 'online', null]),
            'confirmation_number' => fake()->optional()->numerify('CONF-########'),
            'personal_transaction_id' => null,
            'notes' => null,
        ];
    }

    public function withFees(): static
    {
        return $this->state(fn (array $attributes) => [
            'fees_amount' => fake()->randomFloat(2, 5, 50),
        ]);
    }
}
