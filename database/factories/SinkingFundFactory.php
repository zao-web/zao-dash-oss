<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SinkingFund>
 */
class SinkingFundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $targetAmount = fake()->randomFloat(2, 500, 15000);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement([
                'Living Room Couch', 'Roof Replacement', 'Fence Repair',
                'New Dishwasher', 'Car Tires', 'Dental Work',
                'Laptop Replacement', 'HVAC Repair', 'Driveway Resurfacing',
            ]),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement([
                'home_improvement', 'furniture', 'vehicle',
                'appliance', 'medical', 'education', 'other',
            ]),
            'target_amount' => $targetAmount,
            'current_amount' => fake()->randomFloat(2, 0, $targetAmount * 0.8),
            'monthly_contribution' => fake()->randomFloat(2, 50, 500),
            'target_date' => fake()->optional()->dateTimeBetween('+1 month', '+2 years'),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => 'saving',
            'urgency_notes' => null,
        ];
    }

    public function planning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'planning',
            'current_amount' => 0,
            'monthly_contribution' => 0,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ready',
            'current_amount' => $attributes['target_amount'],
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'current_amount' => $attributes['target_amount'],
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'critical',
            'urgency_notes' => fake()->sentence(),
        ]);
    }
}
