<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProfitabilityView>
 */
class ProfitabilityViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $revenue = fake()->randomFloat(2, 1000, 50000);
        $cost = fake()->randomFloat(2, 500, $revenue);
        $profit = $revenue - $cost;
        $hoursLogged = fake()->randomFloat(2, 10, 200);

        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'period_start' => fake()->dateTimeBetween('-6 months', '-1 month')->format('Y-m-d'),
            'period_end' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            'hours_logged' => $hoursLogged,
            'effective_rate' => $hoursLogged > 0 ? round($revenue / $hoursLogged, 2) : 0,
        ];
    }

    public function profitable(): static
    {
        return $this->state(function (array $attributes) {
            $revenue = fake()->randomFloat(2, 5000, 50000);
            $cost = fake()->randomFloat(2, 500, $revenue * 0.6);
            $profit = $revenue - $cost;

            return [
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => round(($profit / $revenue) * 100, 2),
            ];
        });
    }

    public function unprofitable(): static
    {
        return $this->state(function (array $attributes) {
            $revenue = fake()->randomFloat(2, 1000, 10000);
            $cost = $revenue + fake()->randomFloat(2, 500, 5000);
            $profit = $revenue - $cost;

            return [
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => $profit,
                'margin' => round(($profit / $revenue) * 100, 2),
            ];
        });
    }
}
