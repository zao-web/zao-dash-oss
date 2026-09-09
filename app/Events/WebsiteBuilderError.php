<?php

namespace App\Events;

use App\Models\WebsiteProject;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebsiteBuilderError implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WebsiteProject $project,
        public string $message,
        public ?string $phase = null,
        public ?string $code = null,
        public ?array $context = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('website-builder.'.$this->project->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'error';
    }

    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->project->id,
            'message' => $this->message,
            'phase' => $this->phase,
            'code' => $this->code,
            'context' => $this->context,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
