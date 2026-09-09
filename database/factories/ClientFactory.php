<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->optional()->paragraph(),
            'health_score' => fake()->randomFloat(1, 4.0, 10.0),
            'status' => 'active',
            'website' => fake()->optional()->url(),
            'slack_channel' => fake()->optional()->word(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function healthyScore(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->randomFloat(1, 8.0, 10.0),
        ]);
    }

    public function atRiskScore(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->randomFloat(1, 4.0, 6.0),
        ]);
    }

    public function criticalScore(): static
    {
        return $this->state(fn (array $attributes) => [
            'health_score' => fake()->randomFloat(1, 0.0, 4.0),
        ]);
    }
}
