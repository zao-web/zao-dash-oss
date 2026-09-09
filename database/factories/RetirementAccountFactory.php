<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RetirementAccount>
 */
class RetirementAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $accountType = fake()->randomElement(['solo_401k', 'sep_ira', 'defined_benefit', 'traditional_ira', 'roth_ira', 'hsa']);
        $annualLimit = match ($accountType) {
            'solo_401k' => 70000,
            'sep_ira' => 70000,
            'defined_benefit' => 275000,
            'traditional_ira', 'roth_ira' => 7000,
            'hsa' => 8550,
        };

        return [
            'user_id' => User::factory(),
            'account_type' => $accountType,
            'institution_name' => fake()->randomElement(['Fidelity', 'Vanguard', 'Schwab', 'TD Ameritrade', 'E*TRADE']),
            'account_name' => fake()->randomElement(['Solo 401k', 'SEP IRA', 'Defined Benefit Plan', 'Traditional IRA', 'Roth IRA', 'HSA']),
            'current_balance' => fake()->randomFloat(2, 1000, 500000),
            'ytd_contributions' => fake()->randomFloat(2, 0, $annualLimit * 0.75),
            'employee_deferral_ytd' => $accountType === 'solo_401k' ? fake()->randomFloat(2, 0, 23500) : 0,
            'employer_match_ytd' => $accountType === 'solo_401k' ? fake()->randomFloat(2, 0, 46500) : 0,
            'annual_limit' => $annualLimit,
            'tax_year' => now()->year,
            'notes' => null,
        ];
    }

    public function solo401k(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'solo_401k',
            'account_name' => 'Solo 401(k)',
            'annual_limit' => 70000,
        ]);
    }

    public function sepIra(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'sep_ira',
            'account_name' => 'SEP IRA',
            'annual_limit' => 70000,
        ]);
    }

    public function rothIra(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'roth_ira',
            'account_name' => 'Roth IRA',
            'annual_limit' => 7000,
        ]);
    }

    public function hsa(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'hsa',
            'account_name' => 'Health Savings Account',
            'annual_limit' => 8550,
        ]);
    }

    public function maxedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'ytd_contributions' => $attributes['annual_limit'],
        ]);
    }
}
