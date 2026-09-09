<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\XCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\XCredential>
 */
class XCredentialFactory extends Factory
{
    protected $model = XCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'x_user_id' => (string) fake()->unique()->randomNumber(9),
            'username' => fake()->userName(),
            'account_type' => XCredential::TYPE_PERSONAL,
            'name' => fake()->name(),
            'profile_image_url' => fake()->imageUrl(48, 48),
            'description' => fake()->sentence(),
            'verified' => false,
            'followers_count' => fake()->numberBetween(0, 10000),
            'following_count' => fake()->numberBetween(0, 1000),
            'tweet_count' => fake()->numberBetween(0, 5000),
            'access_token' => encrypt(fake()->sha256()),
            'refresh_token' => encrypt(fake()->sha256()),
            'token_expires_at' => now()->addDays(7),
            'scopes' => ['tweet.read', 'users.read', 'bookmark.read'],
            'is_active' => true,
            'last_synced_at' => null,
        ];
    }

    public function withoutBookmarkScope(): static
    {
        return $this->state(fn () => [
            'scopes' => ['tweet.read', 'users.read'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
