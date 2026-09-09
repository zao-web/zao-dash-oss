<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SeoBrandReferenceImage>
 */
class SeoBrandReferenceImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(['general', 'location', 'comparison', 'case-study', 'persona']),
            'storage_path' => 'seo/brand-images/'.fake()->uuid().'.png',
            'wordpress_media_id' => null,
            'description' => fake()->sentence(),
            'style_attributes' => [
                'style' => fake()->randomElement(['modern', 'professional', 'minimal', 'tech']),
                'colors' => ['#3B82F6', '#1E40AF'],
            ],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the image is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a specific category.
     */
    public function category(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }
}
