<?php

namespace Database\Factories;

use App\Models\NorthStarGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NorthStarMilestone>
 */
class NorthStarMilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'north_star_goal_id' => NorthStarGoal::factory(),
            'title' => 'Eliminate all consumer debt',
            'description' => 'Pay off all credit cards, personal loans, and consumer debt completely.',
            'order' => 1,
            'milestone_type' => 'financial',
            'target_amount' => 50000.00,
            'current_amount' => 0.00,
            'tracking_method' => 'debt_total',
            'tracking_config' => ['debt_types' => ['credit_card', 'personal_loan', 'collections']],
            'target_date' => now()->addYears(2),
            'status' => 'in_progress',
        ];
    }

    /**
     * Consumer debt milestone (milestone 1).
     */
    public function consumerDebt(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Eliminate all consumer debt',
            'description' => 'Pay off all credit cards, personal loans, and consumer debt completely.',
            'order' => 1,
            'target_amount' => 50000.00,
            'tracking_method' => 'debt_total',
            'tracking_config' => ['debt_types' => ['credit_card', 'personal_loan', 'collections']],
            'target_date' => now()->addYears(2),
            'status' => 'in_progress',
        ]);
    }

    /**
     * Tax debt milestone (milestone 2).
     */
    public function taxDebt(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Resolve all tax debt',
            'description' => 'Negotiate and pay off all IRS and state tax obligations. This is the biggest mountain to climb.',
            'order' => 2,
            'target_amount' => 200000.00,
            'tracking_method' => 'debt_total',
            'tracking_config' => ['debt_types' => ['tax', 'irs', 'state_tax']],
            'target_date' => now()->addYears(4),
            'status' => 'pending',
        ]);
    }

    /**
     * Emergency fund milestone (milestone 3).
     */
    public function emergencyFund(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Build 6-month emergency fund',
            'description' => 'Save 6 months of living expenses in a high-yield savings account for financial security.',
            'order' => 3,
            'target_amount' => 30000.00,
            'tracking_method' => 'savings_total',
            'tracking_config' => ['account_types' => ['savings']],
            'target_date' => now()->addYears(4)->addMonths(6),
            'status' => 'pending',
        ]);
    }

    /**
     * Land down payment milestone (milestone 4).
     */
    public function landDownPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Save for land down payment',
            'description' => 'Accumulate down payment for 20-100 acre Chehalem Mountain property.',
            'order' => 4,
            'target_amount' => 100000.00,
            'tracking_method' => 'savings_total',
            'tracking_config' => ['account_types' => ['savings', 'investment']],
            'target_date' => now()->addYears(5),
            'status' => 'pending',
        ]);
    }

    /**
     * Purchase property milestone (milestone 5).
     */
    public function purchaseProperty(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Purchase Chehalem Mountain property',
            'description' => 'Find and purchase 20-100 acres on Chehalem Mountain with views of the Willamette Valley.',
            'order' => 5,
            'target_amount' => 400000.00,
            'tracking_method' => 'manual',
            'target_date' => now()->addYears(5)->addMonths(6),
            'status' => 'pending',
            'celebration' => ['action' => 'Walk the land as a family on day one'],
        ]);
    }

    /**
     * Build dream home milestone (milestone 6).
     */
    public function buildHome(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Build dream home',
            'description' => 'Design and build a custom family home on the Chehalem Mountain property.',
            'order' => 6,
            'target_amount' => 800000.00,
            'tracking_method' => 'manual',
            'target_date' => now()->addYears(7),
            'status' => 'pending',
            'celebration' => ['action' => 'First family dinner in the new home'],
        ]);
    }

    /**
     * Completed milestone state.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'current_amount' => $attributes['target_amount'] ?? 50000.00,
        ]);
    }
}
