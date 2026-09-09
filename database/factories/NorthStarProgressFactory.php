<?php

namespace Database\Factories;

use App\Models\NorthStarGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NorthStarProgress>
 */
class NorthStarProgressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'north_star_goal_id' => NorthStarGoal::factory(),
            'snapshot_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'overall_progress_percent' => fake()->randomFloat(2, 0, 25),
            'milestone_progress' => [
                ['id' => 1, 'title' => 'Eliminate all consumer debt', 'percent' => fake()->randomFloat(1, 0, 50), 'status' => 'in_progress'],
                ['id' => 2, 'title' => 'Resolve all tax debt', 'percent' => 0, 'status' => 'pending'],
                ['id' => 3, 'title' => 'Build 6-month emergency fund', 'percent' => 0, 'status' => 'pending'],
                ['id' => 4, 'title' => 'Save for land down payment', 'percent' => 0, 'status' => 'pending'],
                ['id' => 5, 'title' => 'Purchase Chehalem Mountain property', 'percent' => 0, 'status' => 'pending'],
                ['id' => 6, 'title' => 'Build dream home', 'percent' => 0, 'status' => 'pending'],
            ],
            'days_ahead_behind' => fake()->randomFloat(1, -30, 30),
            'ai_insight' => null,
        ];
    }
}
