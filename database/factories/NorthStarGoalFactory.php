<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NorthStarGoal>
 */
class NorthStarGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Chehalem Mountain Dream Home',
            'description' => 'Become completely debt-free, purchase 20-100 acres on Chehalem Mountain in Oregon wine country, and build a custom dream home for the family. A place with views, space, and peace.',
            'why' => 'To give my family a permanent home surrounded by nature, with room to breathe and grow. To break free from the weight of debt and build something lasting. Every hard day of work brings us closer to Chehalem Mountain.',
            'total_cost_estimate' => 1580000.00,
            'target_date' => now()->addYears(7),
            'status' => 'active',
            'imagery' => null,
        ];
    }

    /**
     * Goal in achieved state.
     */
    public function achieved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'achieved',
        ]);
    }

    /**
     * Goal in paused state.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }
}
