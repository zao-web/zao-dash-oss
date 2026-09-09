<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NetWorthSnapshot>
 */
class NetWorthSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $personalCash = fake()->randomFloat(2, 500, 25000);
        $businessCash = fake()->randomFloat(2, 1000, 50000);
        $investmentValue = fake()->randomFloat(2, 0, 100000);
        $totalDebt = fake()->randomFloat(2, 0, 80000);

        $totalAssets = $personalCash + $businessCash + $investmentValue;
        $totalLiabilities = $totalDebt;

        return [
            'user_id' => User::factory(),
            'snapshot_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => $totalAssets - $totalLiabilities,
            'personal_cash' => $personalCash,
            'business_cash' => $businessCash,
            'investment_value' => $investmentValue,
            'total_debt' => $totalDebt,
            'breakdown' => null,
        ];
    }

    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'net_worth' => abs($attributes['net_worth']) + 1000,
            'total_assets' => abs($attributes['net_worth']) + 1000 + $attributes['total_liabilities'],
        ]);
    }

    public function negative(): static
    {
        return $this->state(function (array $attributes) {
            $totalDebt = fake()->randomFloat(2, 50000, 200000);

            return [
                'total_debt' => $totalDebt,
                'total_liabilities' => $totalDebt,
                'net_worth' => $attributes['total_assets'] - $totalDebt,
            ];
        });
    }
}
