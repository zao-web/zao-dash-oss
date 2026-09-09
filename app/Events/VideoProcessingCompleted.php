<?php

namespace App\Events;

use App\Models\Video;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoProcessingCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->video->user_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'video_id' => $this->video->id,
            'uuid' => $this->video->uuid,
            'title' => $this->video->title,
            'status' => $this->video->status,
            'duration' => $this->video->duration,
            'formatted_duration' => $this->video->formatted_duration,
            'thumbnail_url' => $this->video->thumbnail_url,
            'share_url' => $this->video->share_url,
            'task_id' => $this->video->task_id,
            'project_id' => $this->video->project_id,
        ];
    }

    public function broadcastAs(): string
    {
        return 'video.processing.completed';
    }
}
