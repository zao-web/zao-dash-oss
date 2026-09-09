<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialAlert>
 */
class FinancialAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'alert_type' => fake()->randomElement(['shortfall_warning', 'invoice_overdue', 'tax_deadline', 'debt_payment_due', 'collections_deadline', 'balance_threshold', 'revenue_gap_critical']),
            'severity' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'action_text' => fake()->optional()->sentence(),
            'deadline' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'dollar_impact' => fake()->optional()->randomFloat(2, 100, 50000),
            'dollar_cost_of_inaction' => fake()->optional()->randomFloat(2, 10, 5000),
            'related_model_type' => null,
            'related_model_id' => null,
            'metadata' => null,
            'status' => 'open',
            'acknowledged_at' => null,
            'resolved_at' => null,
            'slack_message_ts' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'critical',
        ]);
    }

    public function high(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'high',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);
    }

    public function invoiceOverdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'alert_type' => 'invoice_overdue',
            'severity' => 'high',
        ]);
    }

    public function revenueGap(): static
    {
        return $this->state(fn (array $attributes) => [
            'alert_type' => 'revenue_gap_critical',
            'severity' => 'critical',
        ]);
    }
}
