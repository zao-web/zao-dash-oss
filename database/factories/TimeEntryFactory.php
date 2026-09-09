<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'harvest_id' => $this->faker->unique()->randomNumber(8),
            'harvest_project_id' => $this->faker->randomNumber(8),
            'user_id' => \App\Models\User::factory(),
            'project_id' => null,
            'task_id' => null,
            'spent_date' => now()->subDays($this->faker->numberBetween(0, 30)),
            'hours' => $this->faker->randomFloat(2, 0.5, 8.0),
            'notes' => $this->faker->sentence(),
            'is_running' => false,
            'is_billed' => false,
            'is_locked' => false,
        ];
    }

    /**
     * Indicate that the time entry is running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_running' => true,
            'spent_date' => now(),
        ]);
    }

    /**
     * Indicate that the time entry has been billed.
     */
    public function billed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_billed' => true,
            'is_locked' => true,
        ]);
    }
}
