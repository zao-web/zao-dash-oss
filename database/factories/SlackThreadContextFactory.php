<?php

namespace Database\Factories;

use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlackThreadContext>
 */
class SlackThreadContextFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => SlackChannel::factory(),
            'thread_ts' => (string) $this->faker->unixTime().'.'.$this->faker->randomNumber(6),
            'bot_user_id' => 'B'.strtoupper($this->faker->bothify('##??????')),
            'conversation_history' => [],
            'extracted_intents' => null,
            'pending_actions' => null,
            'completed_actions' => null,
            'context_data' => null,
            'current_state' => 'idle',
            'agent_run_id' => null,
            'last_interaction_at' => now(),
            'expires_at' => now()->addHours(24),
        ];
    }

    public function withHistory(array $history): static
    {
        return $this->state(fn () => ['conversation_history' => $history]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['current_state' => 'processing']);
    }

    public function awaitingResponse(): static
    {
        return $this->state(fn () => ['current_state' => 'awaiting_response']);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }
}
