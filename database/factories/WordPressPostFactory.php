<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WordPressPost>
 */
class WordPressPostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence();

        return [
            'wordpress_site_id' => \App\Models\WordPressSite::factory(),
            'wp_post_id' => $this->faker->unique()->numberBetween(1, 99999),
            'title' => $title,
            'slug' => $this->faker->slug(),
            'status' => 'publish',
            'post_type' => 'post',
            'content' => $this->faker->paragraphs(3, true),
            'excerpt' => $this->faker->paragraph(),
            'published_at' => now(),
        ];
    }

    /**
     * Indicate that the post is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the post is a page.
     */
    public function page(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_type' => 'page',
        ]);
    }
}
