<?php

namespace Database\Factories;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoogleCredentialFactory extends Factory
{
    protected $model = GoogleCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => $this->faker->safeEmail(),
            'access_token' => 'ya29.'.$this->faker->sha256(),
            'refresh_token' => '1//'.$this->faker->sha256(),
            'expires_at' => now()->addHour(),
            'scopes' => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
            'watch_expiration' => now()->addWeek(),
            'calendar_watch_expiration' => now()->addWeek(),
            'watch_resource_id' => $this->faker->uuid(),
            'calendar_watch_resource_id' => $this->faker->uuid(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function needsWatchRenewal(): static
    {
        return $this->state(fn (array $attributes) => [
            'watch_expiration' => now()->subDay(),
        ]);
    }
}
