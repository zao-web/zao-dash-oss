<?php

namespace Database\Factories;

use App\Models\RfpOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfpOpportunity>
 */
class RfpOpportunityFactory extends Factory
{
    protected $model = RfpOpportunity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'slug' => fake()->unique()->slug(),
            'issuing_organization' => fake()->company(),
            'organization_website' => fake()->url(),
            'organization_industry' => fake()->randomElement(['government', 'healthcare', 'tourism', 'education', 'nonprofit']),
            'description' => fake()->paragraph(),
            'source_type' => fake()->randomElement(['email_teaser', 'sam_gov', 'rfp_board', 'web_scrape', 'manual']),
            'status' => 'discovered',
            'fit_score' => fake()->numberBetween(0, 100),
            'priority' => 'medium',
            'budget_min' => fake()->randomFloat(2, 10000, 100000),
            'budget_max' => fake()->randomFloat(2, 100000, 500000),
            'submission_deadline' => fake()->dateTimeBetween('+1 week', '+3 months'),
        ];
    }

    public function qualified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'qualified',
        ]);
    }

    public function pursuing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pursuing',
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
        ]);
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'won',
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'lost',
        ]);
    }

    public function highFit(): static
    {
        return $this->state(fn (array $attributes) => [
            'fit_score' => fake()->numberBetween(80, 100),
        ]);
    }

    public function lowFit(): static
    {
        return $this->state(fn (array $attributes) => [
            'fit_score' => fake()->numberBetween(0, 30),
        ]);
    }
}
