<?php

namespace App\Events;

use App\Models\AgentRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when an agent run status changes.
 */
class AgentRunStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AgentRun $run,
        public string $previousStatus,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('agents.'.$this->run->agent_id),
            new PrivateChannel('agent-runs.'.$this->run->id),
            new Channel('agents'), // Public channel for dashboard
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'agent_id' => $this->run->agent_id,
            'agent_slug' => $this->run->agent->slug,
            'agent_name' => $this->run->agent->name,
            'status' => $this->run->status,
            'previous_status' => $this->previousStatus,
            'started_at' => $this->run->started_at?->toIso8601String(),
            'completed_at' => $this->run->completed_at?->toIso8601String(),
            'cost_usd' => $this->run->cost_usd,
            'invocation_source' => $this->run->invocation_source,
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'run.status.changed';
    }
}
