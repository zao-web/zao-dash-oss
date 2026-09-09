<?php

namespace Database\Factories;

use App\Enums\SeoPageStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoPage>
 */
class SeoPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $service = fake()->randomElement(['WordPress Development', 'WooCommerce', 'Laravel API', 'WordPress Security']);
        $industry = fake()->randomElement(['Healthcare', 'Finance', 'SaaS', 'E-commerce', 'Education']);
        $uniqueId = fake()->unique()->uuid();
        $slug = strtolower(str_replace(' ', '-', "{$service} for {$industry}")).'-'.$uniqueId;

        return [
            'page_url' => "https://example.com/{$slug}",
            'target_keyword' => strtolower("{$service} {$industry}"),
            'page_type' => fake()->randomElement(['service_page', 'comparison', 'how_to', 'category_hub']),
            'meta_title' => "{$service} for {$industry} | Zao",
            'meta_description' => "Expert {$service} services for {$industry} companies. Secure, scalable solutions.",
            'wordpress_post_id' => null,
            'generated_by_agent' => fake()->boolean(70),
            'published_at' => fake()->optional(0.8)->dateTimeBetween('-3 months'),
            'impressions_30d' => fake()->numberBetween(50, 5000),
            'clicks_30d' => fake()->numberBetween(5, 500),
            'avg_position_30d' => fake()->randomFloat(2, 1, 50),
            'ctr_30d' => fake()->randomFloat(2, 0, 5),
            'total_leads' => fake()->numberBetween(0, 10),
            'total_projects' => fake()->numberBetween(0, 5),
            'total_revenue' => fake()->randomFloat(2, 0, 100000),
            'conversion_rate' => fake()->randomFloat(2, 0, 5),
            'last_optimized_at' => fake()->optional()->dateTimeBetween('-2 months'),
            'optimization_count' => fake()->numberBetween(0, 5),
            'status' => SeoPageStatus::Published,
        ];
    }

    /**
     * Set the page status to queued.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeoPageStatus::Queued,
        ]);
    }

    /**
     * Set the page status to failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeoPageStatus::Failed,
        ]);
    }

    /**
     * Set the page status to draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeoPageStatus::Draft,
        ]);
    }
}
