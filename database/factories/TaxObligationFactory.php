<?php

namespace Database\Factories;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxObligation>
 */
class TaxObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory()->taxFederal(),
            'tax_type' => fake()->randomElement(['federal_income', 'state_income', 'self_employment', 'payroll']),
            'tax_year' => fake()->numberBetween(2019, 2025),
            'original_assessment' => fake()->randomFloat(2, 2000, 75000),
            'penalties_accrued' => fake()->randomFloat(2, 0, 5000),
            'interest_accrued' => fake()->randomFloat(2, 0, 3000),
            'resolution_type' => fake()->randomElement(['none', 'installment_agreement', 'offer_in_compromise', 'currently_not_collectible', 'full_payment']),
            'irs_notice_number' => fake()->optional()->numerify('CP####'),
            'installment_monthly' => fake()->optional()->randomFloat(2, 100, 2000),
            'offer_amount' => null,
            'resolution_status' => fake()->randomElement(['not_started', 'in_progress', 'pending_review', 'approved', 'resolved']),
            'collection_statute_expiration' => fake()->optional()->dateTimeBetween('+1 year', '+10 years'),
            'next_action_date' => fake()->optional()->dateTimeBetween('now', '+3 months'),
            'assigned_representative' => fake()->optional()->name(),
        ];
    }

    public function federal(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_type' => 'federal_income',
        ]);
    }

    public function withInstallmentAgreement(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolution_type' => 'installment_agreement',
            'resolution_status' => 'approved',
            'installment_monthly' => fake()->randomFloat(2, 200, 1500),
        ]);
    }

    public function withOfferInCompromise(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolution_type' => 'offer_in_compromise',
            'resolution_status' => 'pending_review',
            'offer_amount' => fake()->randomFloat(2, 1000, 20000),
        ]);
    }
}
