<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WordPressSiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'url' => $this->faker->url(),
            'name' => $this->faker->company(),
            'rest_url' => $this->faker->url().'/wp-json',
            'username' => $this->faker->userName(),
            'application_password' => encrypt($this->faker->password()),
            'mcp_enabled' => false,
            'last_connected_at' => now(),
        ];
    }
}
