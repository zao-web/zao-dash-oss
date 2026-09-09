<?php

namespace Database\Factories;

use App\Models\SlackChannel;
use App\Models\SlackUserWatchlistItem;
use App\Models\SlackWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlackUserWatchlistItem>
 */
class SlackUserWatchlistItemFactory extends Factory
{
    /**
     * Configure the factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (SlackUserWatchlistItem $item): void {
            if (! $item->workspace_id) {
                return;
            }

            if ($item->slack_channel_id) {
                return;
            }

            $channel = SlackChannel::factory()->create([
                'workspace_id' => $item->workspace_id,
                'channel_name' => $this->faker->slug(2),
            ]);

            $item->slack_channel_id = $channel->id;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => SlackWorkspace::factory(),
            'slack_user_id' => 'U'.$this->faker->numerify('#####'),
            'slack_channel_id' => 0,
            'label' => $this->faker->optional()->company(),
            'source' => 'manual',
            'is_active' => true,
        ];
    }
}
