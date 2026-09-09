<?php

namespace App\Events;

use App\Models\WebsiteProject;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebsiteBuilderMessageChunk implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WebsiteProject $project,
        public string $messageId,
        public string $chunk,
        public bool $isStart = false,
        public bool $isEnd = false,
        public ?array $metadata = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('website-builder.'.$this->project->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.chunk';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'chunk' => $this->chunk,
            'is_start' => $this->isStart,
            'is_end' => $this->isEnd,
            'metadata' => $this->metadata,
        ];
    }
}
