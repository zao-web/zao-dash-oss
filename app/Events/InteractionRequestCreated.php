<?php

namespace App\Events;

use App\Models\InteractionRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an agent needs user input.
 *
 * This event pushes the interaction question to the user's browser
 * where it will appear as a modal for response.
 */
class InteractionRequestCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public InteractionRequest $interaction,
        public int $userId,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->userId}.interactions"),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $agentRun = $this->interaction->agentRun;

        return [
            'interaction_id' => $this->interaction->id,
            'run_id' => $this->interaction->agent_run_id,
            'agent_id' => $agentRun?->agent_id,
            'agent_name' => $agentRun?->agent?->name,
            'agent_slug' => $agentRun?->agent?->slug,
            'question_type' => $this->interaction->question_type,
            'question' => $this->interaction->question_content,
            'options' => $this->interaction->options,
            'context' => $this->interaction->context,
            'expires_at' => $this->interaction->expires_at->toIso8601String(),
            'created_at' => $this->interaction->created_at->toIso8601String(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'interaction.created';
    }
}
