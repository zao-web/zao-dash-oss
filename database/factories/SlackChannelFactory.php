<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackChannel>
 */
class SlackChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => \App\Models\SlackWorkspace::factory(),
            'channel_id' => 'C'.$this->faker->regexify('[A-Z0-9]{8}'),
            'channel_name' => $this->faker->slug(2),
            'is_private' => false,
            'is_shared' => false,
            'classification' => 'internal',
            'monitoring_enabled' => true,
        ];
    }

    /**
     * Indicate that the channel is a client channel.
     */
    public function clientChannel(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_shared' => true,
            'classification' => 'client',
        ]);
    }

    /**
     * Indicate that monitoring is disabled.
     */
    public function notMonitored(): static
    {
        return $this->state(fn (array $attributes) => [
            'monitoring_enabled' => false,
        ]);
    }
}
