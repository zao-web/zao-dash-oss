<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProfitabilitySnapshot>
 */
class ProfitabilitySnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hoursLogged = $this->faker->randomFloat(2, 10, 200);
        $hoursBillable = $this->faker->randomFloat(2, 5, $hoursLogged);
        $revenue = $this->faker->randomFloat(2, 1000, 20000);
        $cost = $this->faker->randomFloat(2, 500, $revenue * 0.7);
        $profit = $revenue - $cost;
        $marginPercent = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return [
            'period_type' => $this->faker->randomElement(['daily', 'weekly', 'monthly']),
            'period_start' => $this->faker->dateTimeBetween('-1 month', '-1 week'),
            'period_end' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'hours_logged' => $hoursLogged,
            'hours_billable' => $hoursBillable,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin_percent' => $marginPercent,
            'client_id' => null,
            'project_id' => null,
            'user_id' => null,
        ];
    }
}
