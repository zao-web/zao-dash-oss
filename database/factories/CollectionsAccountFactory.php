<?php

namespace Database\Factories;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CollectionsAccount>
 */
class CollectionsAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory()->collections(),
            'original_creditor' => fake()->company(),
            'collection_agency' => fake()->randomElement(['Midland Credit Management', 'Portfolio Recovery Associates', 'Encore Capital Group', 'LVNV Funding']),
            'agency_contact' => fake()->optional()->name(),
            'agency_phone' => fake()->optional()->phoneNumber(),
            'agency_reference' => fake()->optional()->numerify('REF-########'),
            'date_sent_to_collections' => fake()->dateTimeBetween('-3 years', '-3 months'),
            'statute_of_limitations' => fake()->optional()->dateTimeBetween('+6 months', '+5 years'),
            'last_contact_date' => fake()->optional()->dateTimeBetween('-6 months', 'now'),
            'settlement_offered' => null,
            'settlement_accepted' => null,
            'dispute_filed' => false,
            'dispute_date' => null,
            'correspondence_log' => null,
        ];
    }

    public function disputed(): static
    {
        return $this->state(fn (array $attributes) => [
            'dispute_filed' => true,
            'dispute_date' => fake()->dateTimeBetween('-3 months', 'now'),
        ]);
    }

    public function withSettlement(): static
    {
        return $this->state(fn (array $attributes) => [
            'settlement_offered' => fake()->randomFloat(2, 500, 10000),
            'settlement_accepted' => fake()->optional()->randomFloat(2, 300, 8000),
        ]);
    }
}
