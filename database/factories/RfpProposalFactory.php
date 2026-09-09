<?php

namespace Database\Factories;

use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfpProposal>
 */
class RfpProposalFactory extends Factory
{
    protected $model = RfpProposal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rfp_opportunity_id' => RfpOpportunity::factory(),
            'version' => 1,
            'title' => fake()->sentence(5),
            'executive_summary' => fake()->paragraphs(2, true),
            'status' => 'draft',
            'total_price' => fake()->randomFloat(2, 10000, 200000),
        ];
    }

    public function review(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'review',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'submitted_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
