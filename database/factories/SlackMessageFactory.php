<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackMessage>
 */
class SlackMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $timestamp = microtime(true);
        $workspace = \App\Models\SlackWorkspace::factory()->create();

        return [
            'workspace_id' => $workspace->id,
            'channel_id' => \App\Models\SlackChannel::factory()->create(['workspace_id' => $workspace->id])->id,
            'message_ts' => number_format($timestamp, 6, '.', ''),
            'user_id' => 'U'.$this->faker->regexify('[A-Z0-9]{8}'),
            'user_name' => $this->faker->userName(),
            'content' => $this->faker->sentence(),
            'user_is_external' => false,
            'thread_ts' => null,
            // Leave sent_at null by default so tests passing created_at for
            // date-range scenarios fall through to the coalesce path.
            'sent_at' => null,
        ];
    }

    /**
     * Indicate that the user is external (client).
     */
    public function fromExternal(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_is_external' => true,
        ]);
    }

    /**
     * Indicate that this is a thread reply.
     */
    public function threadReply(): static
    {
        return $this->state(fn (array $attributes) => [
            'thread_ts' => number_format(microtime(true) - 3600, 6, '.', ''),
        ]);
    }
}
