<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IdealCustomerProfile>
 */
class IdealCustomerProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Enterprise SaaS',
            'E-commerce Startups',
            'Professional Services',
            'Healthcare Tech',
            'FinTech Companies',
            'Media & Publishing',
            'Non-Profit Organizations',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'industries' => fake()->randomElements(
                ['SaaS', 'E-commerce', 'FinTech', 'Healthcare', 'Media', 'Professional Services'],
                rand(1, 3)
            ),
            'company_sizes' => fake()->randomElements(
                ['1-10', '11-50', '51-200', '201-500', '500+'],
                rand(1, 3)
            ),
            'locations' => fake()->randomElements(
                ['United States', 'Canada', 'United Kingdom', 'Australia'],
                rand(1, 2)
            ),
            'tech_stack' => fake()->randomElements(
                ['WordPress', 'Laravel', 'React', 'Vue', 'Shopify', 'WooCommerce', 'PHP', 'JavaScript'],
                rand(2, 5)
            ),
            'tools_used' => fake()->randomElements(
                ['Slack', 'Jira', 'GitHub', 'Figma', 'HubSpot'],
                rand(1, 3)
            ),
            'buying_signals' => fake()->randomElements(
                ['recent_funding', 'hiring_developers', 'website_redesign', 'technology_migration'],
                rand(1, 3)
            ),
            'pain_points' => fake()->randomElements(
                ['Slow website performance', 'Outdated design', 'Poor mobile experience', 'SEO issues'],
                rand(1, 3)
            ),
            'avg_deal_value' => fake()->randomFloat(2, 5000, 100000),
            'weight_industry' => 25,
            'weight_size' => 20,
            'weight_tech' => 30,
            'weight_signals' => 25,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function highValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'avg_deal_value' => fake()->randomFloat(2, 50000, 200000),
        ]);
    }
}
