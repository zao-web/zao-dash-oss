<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'client_id' => \App\Models\Client::factory(),
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(['active', 'on_hold', 'completed', 'archived']),
            'type' => $this->faker->randomElement(['retainer', 'project', 'support']),
            'budget' => $this->faker->optional()->randomFloat(2, 1000, 100000),
            'start_date' => $this->faker->optional()->dateTimeBetween('-6 months', 'now'),
            'end_date' => $this->faker->optional()->dateTimeBetween('now', '+6 months'),
        ];
    }

    public function withDates(?\Carbon\Carbon $start = null, ?\Carbon\Carbon $end = null): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => $start ?? now(),
            'end_date' => $end ?? now()->addMonths(6),
        ]);
    }
}
