<?php

namespace App\Events;

use App\Models\Video;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionItemsExtracted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video,
        public array $actionItems
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->video->user_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'video_id' => $this->video->id,
            'video_uuid' => $this->video->uuid,
            'video_title' => $this->video->title,
            'action_items' => $this->actionItems,
            'action_items_count' => count($this->actionItems),
        ];
    }

    public function broadcastAs(): string
    {
        return 'action-items.extracted';
    }
}
