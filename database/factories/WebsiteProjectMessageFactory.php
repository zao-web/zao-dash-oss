<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebsiteProject;
use App\Models\WebsiteProjectMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebsiteProjectMessage>
 */
class WebsiteProjectMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'website_project_id' => WebsiteProject::factory(),
            'user_id' => User::factory(),
            'role' => $this->faker->randomElement(['user', 'assistant', 'system']),
            'content' => $this->faker->paragraph(),
            'metadata' => null,
            'status' => 'sent',
        ];
    }

    public function fromUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => WebsiteProjectMessage::ROLE_USER,
        ]);
    }

    public function fromAssistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => WebsiteProjectMessage::ROLE_ASSISTANT,
            'user_id' => null,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => WebsiteProjectMessage::ROLE_SYSTEM,
            'user_id' => null,
        ]);
    }
}
