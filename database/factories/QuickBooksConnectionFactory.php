<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuickBooksConnection>
 */
class QuickBooksConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'realm_id' => $this->faker->unique()->numerify('#########'),
            'company_name' => $this->faker->company(),
            'access_token' => $this->faker->uuid(),
            'refresh_token' => $this->faker->uuid(),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'last_synced_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
            'sync_enabled' => true,
        ];
    }
}
