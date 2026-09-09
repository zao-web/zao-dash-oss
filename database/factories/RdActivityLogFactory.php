<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RdActivityLog>
 */
class RdActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qualifies = fake()->boolean(70);
        $hours = fake()->randomFloat(2, 0.5, 8);

        return [
            'user_id' => User::factory(),
            'activity_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'hours' => $hours,
            'description' => fake()->randomElement([
                'Developed new algorithm for automated content generation pipeline',
                'Researched and prototyped machine learning model for lead scoring',
                'Built experimental API integration with uncertainty analysis',
                'Designed novel data processing architecture for real-time analytics',
                'Investigated performance optimization techniques for database queries',
            ]),
            'qualifies_for_rd' => $qualifies,
            'qualification_reason' => $qualifies ? fake()->randomElement([
                'Technological uncertainty - novel approach to data processing',
                'Process of experimentation - iterating on ML model architectures',
                'Elimination of uncertainty through systematic testing',
                'New or improved business component with technical uncertainty',
            ]) : null,
            'project_id' => null,
            'wage_amount' => round($hours * fake()->randomFloat(2, 50, 150), 2),
        ];
    }

    public function qualifying(): static
    {
        return $this->state(fn (array $attributes) => [
            'qualifies_for_rd' => true,
            'qualification_reason' => 'Technological uncertainty - novel approach',
        ]);
    }

    public function nonQualifying(): static
    {
        return $this->state(fn (array $attributes) => [
            'qualifies_for_rd' => false,
            'qualification_reason' => null,
        ]);
    }

    public function withProject(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => Project::factory(),
        ]);
    }

    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_date' => fake()->dateTimeBetween("{$year}-01-01", "{$year}-12-31"),
        ]);
    }
}
