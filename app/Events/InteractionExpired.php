<?php

namespace App\Events;

use App\Models\InteractionRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an interaction request expires without a response.
 *
 * This event notifies the dashboard and other channels that the interaction
 * can no longer be responded to, allowing UIs to update accordingly.
 */
class InteractionExpired implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public InteractionRequest $interaction,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // Broadcast to the run's channel
        $channels[] = new PrivateChannel("agent-runs.{$this->interaction->agent_run_id}");

        // Also broadcast to all team users (for multi-user coordination)
        $channels[] = new Channel('interactions');

        return $channels;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'interaction_id' => $this->interaction->id,
            'run_id' => $this->interaction->agent_run_id,
            'expired_at' => now()->toIso8601String(),
            'expires_at' => $this->interaction->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'interaction.expired';
    }
}
