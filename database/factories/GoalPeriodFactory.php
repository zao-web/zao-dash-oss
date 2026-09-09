<?php

namespace Database\Factories;

use App\Models\GoalPeriod;
use App\Models\StrategicGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoalPeriodFactory extends Factory
{
    protected $model = GoalPeriod::class;

    public function definition(): array
    {
        return [
            'strategic_goal_id' => StrategicGoal::factory(),
            'period_type' => 'monthly',
            'period_label' => now()->format('M'),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'revenue_target' => $this->faker->randomFloat(2, 10000, 50000),
            'revenue_actual' => 0,
            'leads_target' => $this->faker->numberBetween(5, 20),
            'leads_actual' => 0,
            'closed_deals_target' => $this->faker->numberBetween(1, 5),
            'closed_deals_actual' => 0,
            'pipeline_target' => $this->faker->randomFloat(2, 50000, 150000),
            'pipeline_actual' => 0,
            'variance_pct' => 0,
            'status' => 'pending',
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'yearly',
            'period_label' => (string) now()->year,
            'period_start' => now()->startOfYear(),
            'period_end' => now()->endOfYear(),
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'quarterly',
            'period_label' => 'Q'.now()->quarter,
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'monthly',
            'period_label' => now()->format('M'),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'weekly',
            'period_label' => 'W'.now()->weekOfYear,
            'period_start' => now()->startOfWeek(),
            'period_end' => now()->endOfWeek(),
        ]);
    }
}
