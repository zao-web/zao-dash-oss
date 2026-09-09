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
 * Broadcast when a user responds to an interaction request.
 *
 * This event notifies other tabs/sessions that a response was received,
 * allowing them to close their modals and avoid duplicate responses.
 */
class InteractionResponseReceived implements ShouldBroadcast
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
        // This uses a public channel for simplicity - can be made private if needed
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
            'responded_at' => $this->interaction->responded_at?->toIso8601String(),
            'responded_via' => $this->interaction->responded_via,
            'responded_by_id' => $this->interaction->responded_by_id,
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'interaction.responded';
    }
}
