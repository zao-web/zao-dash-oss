<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlaidConnection>
 */
class PlaidConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'institution_name' => fake()->randomElement(['Chase', 'Bank of America', 'Wells Fargo', 'Capital One', 'Ally Bank']),
            'institution_id' => fake()->bothify('ins_######'),
            'access_token' => fake()->sha256(),
            'item_id' => fake()->unique()->uuid(),
            'cursor' => null,
            'status' => 'active',
            'error_code' => null,
            'error_message' => null,
            'consent_expiration' => fake()->optional()->dateTimeBetween('+6 months', '+2 years'),
            'last_synced_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'products' => ['transactions', 'auth'],
        ];
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'error_code' => 'ITEM_LOGIN_REQUIRED',
            'error_message' => 'The login details of this item have changed.',
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disconnected',
        ]);
    }
}
