<?php

namespace Database\Factories;

use App\Models\HarvestCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HarvestCredentialFactory extends Factory
{
    protected $model = HarvestCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'access_token' => 'harvest.'.$this->faker->sha256(),
            'refresh_token' => 'harvest_refresh.'.$this->faker->sha256(),
            'expires_at' => now()->addMonth(),
            'account_id' => $this->faker->randomNumber(6),
            'account_name' => $this->faker->company(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }
}
