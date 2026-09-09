<?php

namespace Database\Factories;

use App\Models\StrategicGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StrategicGoalFactory extends Factory
{
    protected $model = StrategicGoal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->sentence(3),
            'fiscal_year' => now()->year,
            'revenue_target' => $this->faker->randomFloat(2, 100000, 500000),
            'margin_target_pct' => $this->faker->randomFloat(2, 15, 35),
            'profit_target' => $this->faker->randomFloat(2, 20000, 100000),
            'status' => 'active',
            'assumptions' => [
                'avg_deal_size' => 25000,
                'win_rate' => 25,
                'sales_cycle_days' => 45,
            ],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function achieved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'achieved',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }
}
