<?php

namespace Database\Factories;

use App\Models\RfpOpportunity;
use App\Models\RfpOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfpOutcome>
 */
class RfpOutcomeFactory extends Factory
{
    protected $model = RfpOutcome::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rfp_opportunity_id' => RfpOpportunity::factory(),
            'outcome' => fake()->randomElement(['won', 'lost', 'no_response']),
            'feedback_raw' => fake()->optional()->paragraph(),
        ];
    }
}
