<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'website' => fake()->url(),
            'description' => fake()->paragraph(),
            'stage' => fake()->randomElement(['new', 'qualified', 'proposal', 'negotiation']),
            'source' => fake()->randomElement(['website', 'referral', 'linkedin', 'cold_outreach']),
            'deal_value' => fake()->randomFloat(2, 5000, 100000),
            'probability' => fake()->numberBetween(10, 90),
            'expected_close_date' => fake()->dateTimeBetween('+1 week', '+3 months'),
        ];
    }

    public function withAttribution(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_touch_page_url' => 'https://example.com/laravel-development',
            'first_touch_keyword' => 'laravel development services',
            'first_touch_source' => 'google',
            'first_touch_medium' => 'organic',
            'first_touch_campaign' => null,
            'last_touch_page_url' => 'https://example.com/contact',
            'pages_viewed' => fake()->numberBetween(2, 10),
            'time_on_site_seconds' => fake()->numberBetween(60, 600),
            'max_scroll_depth' => fake()->numberBetween(50, 100),
            'ga4_client_id' => fake()->uuid(),
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'won',
            'converted_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'probability' => 100,
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'lost',
            'lost_reason' => fake()->randomElement(['budget', 'timing', 'competitor', 'no_decision']),
            'probability' => 0,
        ]);
    }
}
