<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

class GitHubInstallationFactory extends Factory
{
    protected $model = GitHubInstallation::class;

    public function definition(): array
    {
        return [
            'installation_id' => $this->faker->randomNumber(8),
            'account_type' => $this->faker->randomElement(['User', 'Organization']),
            'account_login' => $this->faker->userName(),
            'account_id' => $this->faker->randomNumber(8),
            'access_token' => 'ghs_'.$this->faker->sha256(),
            'token_expires_at' => now()->addHour(),
            'permissions' => [
                'contents' => 'read',
                'issues' => 'write',
                'pull_requests' => 'write',
            ],
            'connected_at' => now(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'Organization',
            'account_login' => $this->faker->company(),
        ]);
    }

    public function expiredToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subHour(),
        ]);
    }
}
