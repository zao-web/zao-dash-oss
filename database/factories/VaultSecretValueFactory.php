<?php

namespace Database\Factories;

use App\Models\VaultSecret;
use App\Models\VaultSecretValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class VaultSecretValueFactory extends Factory
{
    protected $model = VaultSecretValue::class;

    public function definition(): array
    {
        return [
            'vault_secret_id' => VaultSecret::factory(),
            'environment' => $this->faker->randomElement([null, 'production', 'staging', 'development']),
            'encrypted_value' => Crypt::encryptString($this->faker->uuid()),
            'value_fingerprint' => null,
            'expires_at' => null,
            'is_active' => true,
            'last_accessed_at' => null,
            'access_count' => 0,
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => ['environment' => 'production']);
    }

    public function staging(): static
    {
        return $this->state(fn (array $attributes) => ['environment' => 'staging']);
    }

    public function development(): static
    {
        return $this->state(fn (array $attributes) => ['environment' => 'development']);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => ['environment' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function withValue(string $value): static
    {
        return $this->state(fn (array $attributes) => [
            'encrypted_value' => Crypt::encryptString($value),
        ]);
    }
}
