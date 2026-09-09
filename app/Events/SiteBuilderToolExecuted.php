<?php

namespace App\Events;

use App\Models\SiteBuilderProject;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteBuilderToolExecuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SiteBuilderProject $project,
        public string $toolName,
        public ?string $action = null,
        public ?array $result = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('site-builder.'.$this->project->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tool.executed';
    }

    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->project->id,
            'tool_name' => $this->toolName,
            'action' => $this->action,
            'result' => $this->result,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
