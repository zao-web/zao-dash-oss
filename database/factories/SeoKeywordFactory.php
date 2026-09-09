<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoKeyword>
 */
class SeoKeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $service = fake()->randomElement(['wordpress development', 'woocommerce', 'laravel api', 'wordpress security']);
        $modifier = fake()->randomElement(['for healthcare', 'for finance', 'best', 'vs shopify', 'tutorial']);

        return [
            'keyword' => "{$service} {$modifier}",
            'intent' => fake()->randomElement(['transactional', 'informational', 'navigational']),
            'estimated_monthly_volume' => fake()->numberBetween(10, 5000),
            'difficulty_score' => fake()->numberBetween(10, 100),
            'source' => fake()->randomElement(['google_keyword_planner', 'manual', 'competitor']),
            'current_position' => fake()->optional()->randomFloat(2, 1, 100),
            'best_position' => fake()->optional()->randomFloat(2, 1, 100),
            'seo_page_id' => null,
            'status' => 'active',
            'first_tracked_at' => fake()->dateTimeBetween('-6 months'),
            'last_checked_at' => fake()->dateTimeBetween('-7 days'),
        ];
    }
}
