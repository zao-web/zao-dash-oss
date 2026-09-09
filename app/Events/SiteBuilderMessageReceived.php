<?php

namespace App\Events;

use App\Models\SiteBuilderProject;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteBuilderMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SiteBuilderProject $project,
        public string $content,
        public string $role = 'assistant',
        public ?string $messageId = null,
        public ?array $metadata = null
    ) {
        $this->messageId = $messageId ?? 'msg_'.time().'_'.bin2hex(random_bytes(4));
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('site-builder.'.$this->project->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'project_id' => $this->project->id,
            'content' => $this->content,
            'role' => $this->role,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
