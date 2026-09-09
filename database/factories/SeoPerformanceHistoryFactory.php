<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoPerformanceHistory>
 */
class SeoPerformanceHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $impressions = fake()->numberBetween(50, 5000);
        $clicks = fake()->numberBetween(5, (int) ($impressions * 0.1));

        return [
            'seo_page_id' => \App\Models\SeoPage::factory(),
            'seo_keyword_id' => null,
            'snapshot_date' => fake()->dateTimeBetween('-3 months'),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'avg_position' => fake()->randomFloat(2, 1, 50),
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
            'sessions' => fake()->numberBetween(0, $clicks),
            'conversions' => fake()->numberBetween(0, (int) ($clicks * 0.05)),
            'bounce_rate' => fake()->randomFloat(2, 20, 80),
            'avg_session_duration' => fake()->numberBetween(30, 600),
        ];
    }
}
