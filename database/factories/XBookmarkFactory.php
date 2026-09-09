<?php

namespace Database\Factories;

use App\Models\XBookmark;
use App\Models\XCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\XBookmark>
 */
class XBookmarkFactory extends Factory
{
    protected $model = XBookmark::class;

    public function definition(): array
    {
        return [
            'x_credential_id' => XCredential::factory(),
            'tweet_id' => (string) fake()->unique()->numberBetween(1000000000000000000, 9999999999999999999),
            'author_id' => (string) fake()->numberBetween(100000000, 999999999),
            'author_username' => fake()->userName(),
            'author_name' => fake()->name(),
            'text' => fake()->sentence(10),
            'tweet_created_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'urls' => null,
            'mentions' => null,
            'hashtags' => null,
            'media' => null,
            'like_count' => fake()->numberBetween(0, 10000),
            'retweet_count' => fake()->numberBetween(0, 5000),
            'reply_count' => fake()->numberBetween(0, 500),
            'quote_count' => fake()->numberBetween(0, 200),
            'status' => XBookmark::STATUS_PENDING,
        ];
    }

    public function analyzed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => XBookmark::STATUS_ANALYZED,
            'analyzed_at' => now(),
            'category' => fake()->randomElement([
                XBookmark::CATEGORY_AI_MODEL,
                XBookmark::CATEGORY_PROMPT_TECHNIQUE,
                XBookmark::CATEGORY_FEATURE_IDEA,
                XBookmark::CATEGORY_TOOL,
            ]),
            'relevance_score' => fake()->numberBetween(0, 100),
        ]);
    }

    public function actionable(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => XBookmark::STATUS_ACTIONABLE,
            'analyzed_at' => now(),
            'category' => XBookmark::CATEGORY_FEATURE_IDEA,
            'relevance_score' => fake()->numberBetween(70, 100),
            'action_summary' => fake()->sentence(),
        ]);
    }

    public function withUrls(int $count = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'urls' => collect(range(1, $count))->map(fn () => [
                'url' => fake()->url(),
                'title' => fake()->sentence(3),
                'description' => fake()->sentence(10),
            ])->all(),
        ]);
    }

    public function enriched(): static
    {
        return $this->state(fn (array $attributes) => [
            'enriched_at' => now(),
            'enriched_content' => [
                'full_text' => $attributes['text'] ?? fake()->paragraph(),
                'url_summaries' => [],
                'thread_context' => null,
                'enrichment_source' => 'oembed',
            ],
        ]);
    }
}
