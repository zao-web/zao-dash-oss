<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxOptimizationScenario>
 */
class TaxOptimizationScenarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grossIncome = fake()->randomFloat(2, 100000, 500000);
        $salaryPercent = fake()->randomFloat(2, 0.30, 0.50);
        $salary = round($grossIncome * $salaryPercent, 2);
        $distributions = round($grossIncome - $salary, 2);
        $totalTax = round($grossIncome * fake()->randomFloat(2, 0.15, 0.35), 2);

        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Current Scenario', 'Optimized Plan', 'What-If Analysis']),
            'tax_year' => fake()->numberBetween(2024, 2026),
            'scenario_type' => fake()->randomElement(['current', 'optimized', 'what_if']),
            'gross_income' => $grossIncome,
            's_corp_salary' => $salary,
            'distributions' => $distributions,
            'retirement_contributions' => [
                'solo_401k_employee' => 23500,
                'solo_401k_employer' => round($salary * 0.25, 2),
                'sep_ira' => 0,
                'defined_benefit' => 0,
            ],
            'real_estate_deductions' => null,
            'rd_credit_amount' => 0,
            'deductions' => [
                'home_office' => 3500,
                'vehicle' => 5000,
                'hsa' => 8550,
                'section_179' => 0,
                'augusta_rule' => 7000,
                'health_insurance' => 18000,
                'charitable' => 0,
            ],
            'qbi_deduction' => round($distributions * 0.20, 2),
            'total_taxable_income' => round($grossIncome * 0.60, 2),
            'federal_tax' => round($totalTax * 0.70, 2),
            'state_tax' => round($totalTax * 0.20, 2),
            'se_tax' => round($totalTax * 0.10, 2),
            'fica_tax' => round($salary * 0.153, 2),
            'total_tax' => $totalTax,
            'effective_rate' => round(($totalTax / $grossIncome) * 100, 2),
            'strategies_applied' => ['s_corp', 'solo_401k', 'qbi'],
            'comparison_baseline_id' => null,
            'savings_vs_baseline' => null,
            'notes' => null,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Current Scenario',
            'scenario_type' => 'current',
        ]);
    }

    public function optimized(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Optimized Plan',
            'scenario_type' => 'optimized',
        ]);
    }

    public function whatIf(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'What-If Analysis',
            'scenario_type' => 'what_if',
        ]);
    }
}
