<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackWorkspace>
 */
class SlackWorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => 'T'.$this->faker->regexify('[A-Z0-9]{8}'),
            'workspace_name' => $this->faker->company(),
            'bot_user_id' => 'U'.$this->faker->regexify('[A-Z0-9]{8}'),
            'access_token' => 'xoxb-'.$this->faker->regexify('[0-9]{12}-[0-9]{12}-[A-Za-z0-9]{24}'),
        ];
    }
}
