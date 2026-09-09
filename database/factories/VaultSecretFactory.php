<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VaultSecret>
 */
class VaultSecretFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'key' => $this->faker->unique()->slug(),
            'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString($this->faker->uuid()),
            'category' => $this->faker->randomElement([
                \App\Models\VaultSecret::CATEGORY_API_KEY,
                \App\Models\VaultSecret::CATEGORY_OAUTH,
                \App\Models\VaultSecret::CATEGORY_CREDENTIAL,
                \App\Models\VaultSecret::CATEGORY_CERTIFICATE,
                \App\Models\VaultSecret::CATEGORY_OTHER,
            ]),
            'description' => $this->faker->sentence(),
            'project_id' => null,
            'client_id' => null,
            'allowed_agents' => null,
            'allowed_users' => null,
            'is_sensitive' => $this->faker->boolean(70),
            'is_active' => true,
            'created_by' => \App\Models\User::factory(),
            'updated_by' => null,
            'last_accessed_at' => null,
            'access_count' => 0,
            'expires_at' => null,
        ];
    }
}
