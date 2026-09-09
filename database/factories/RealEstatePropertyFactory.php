<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RealEstateProperty>
 */
class RealEstatePropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasePrice = fake()->randomFloat(2, 150000, 1500000);
        $landPercent = fake()->randomFloat(2, 15, 35);
        $landValue = round($purchasePrice * ($landPercent / 100), 2);
        $buildingValue = round($purchasePrice - $landValue, 2);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Main Street Duplex', 'Lake House STR', 'Downtown Commercial', 'Mountain Cabin']),
            'address' => fake()->address(),
            'property_type' => fake()->randomElement(['residential', 'commercial', 'str', 'land']),
            'purchase_price' => $purchasePrice,
            'purchase_date' => fake()->dateTimeBetween('-10 years', 'now'),
            'land_value' => $landValue,
            'building_value' => $buildingValue,
            'fair_market_value' => round($purchasePrice * fake()->randomFloat(2, 0.90, 1.30), 2),
            'cost_segregation_done' => false,
            'depreciation_schedule' => null,
            'annual_depreciation' => round($buildingValue / 27.5, 2),
            'accumulated_depreciation' => fake()->randomFloat(2, 0, $buildingValue * 0.30),
            'is_str' => false,
            'average_stay_days' => null,
            'material_participation_hours' => 0,
            'rental_income_annual' => fake()->randomFloat(2, 12000, 120000),
            'rental_expenses_annual' => fake()->randomFloat(2, 5000, 60000),
            'opportunity_zone' => false,
            'oz_investment_date' => null,
            'notes' => null,
            'metadata' => null,
        ];
    }

    public function residential(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_type' => 'residential',
            'is_str' => false,
        ]);
    }

    public function commercial(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_type' => 'commercial',
            'annual_depreciation' => round((float) $attributes['building_value'] / 39, 2),
        ]);
    }

    public function shortTermRental(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_type' => 'str',
            'is_str' => true,
            'average_stay_days' => fake()->randomFloat(1, 2, 14),
            'material_participation_hours' => fake()->randomFloat(1, 50, 500),
        ]);
    }

    public function withCostSeg(): static
    {
        return $this->state(function (array $attributes) {
            $buildingValue = (float) $attributes['building_value'];

            return [
                'cost_segregation_done' => true,
                'depreciation_schedule' => [
                    'five_year' => round($buildingValue * 0.20, 2),
                    'seven_year' => round($buildingValue * 0.08, 2),
                    'fifteen_year' => round($buildingValue * 0.12, 2),
                    'structure' => round($buildingValue * 0.60, 2),
                ],
            ];
        });
    }

    public function opportunityZone(): static
    {
        return $this->state(fn (array $attributes) => [
            'opportunity_zone' => true,
            'oz_investment_date' => fake()->dateTimeBetween('-5 years', 'now'),
        ]);
    }
}
