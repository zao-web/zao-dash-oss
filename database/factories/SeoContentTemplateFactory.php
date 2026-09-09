<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoContentTemplate>
 */
class SeoContentTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['service_page', 'comparison', 'how_to', 'category_hub', 'location_page']);

        return [
            'template_name' => fake()->unique()->words(3, true).' template',
            'template_type' => $type,
            'prompt_template' => 'Generate a {page_type} page about {service} for {industry} companies...',
            'variables' => ['service', 'industry', 'city'],
            'avg_conversion_rate' => fake()->randomFloat(2, 0, 5),
            'pages_generated' => fake()->numberBetween(0, 50),
            'total_leads' => fake()->numberBetween(0, 25),
            'status' => 'active',
        ];
    }
}
