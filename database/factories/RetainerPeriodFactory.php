<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RetainerPeriod>
 */
class RetainerPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'hours_included' => 20,
            'hours_used' => 0,
            'rollover_hours' => 0,
            'overage_rate' => 150.00,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'status' => 'active',
            'tier' => 'app_and_web',
            'monthly_amount' => 5000.00,
            'internal_hourly_rate' => 250.00,
            'ai_equivalent_hourly_rate' => 50.00,
            'health_status' => 'healthy',
            'last_client_activity_at' => now()->subDays(5),
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'health_status' => 'critical',
            'effective_margin_percent' => -10.00,
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn () => [
            'health_status' => 'warning',
            'effective_margin_percent' => 15.00,
        ]);
    }

    public function silent(): static
    {
        return $this->state(fn () => [
            'health_status' => 'silent',
            'last_client_activity_at' => now()->subDays(60),
        ]);
    }

    public function withHighMargin(): static
    {
        return $this->state(fn () => [
            'monthly_amount' => 10000.00,
            'hours_included' => 40,
        ]);
    }
}
