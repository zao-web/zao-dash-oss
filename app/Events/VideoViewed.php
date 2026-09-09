<?php

namespace App\Events;

use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoViewed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video,
        public VideoView $view,
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
            'video_title' => $this->video->title,
            'video_uuid' => $this->video->uuid,
            'share_url' => $this->video->share_url,
            'view' => [
                'id' => $this->view->id,
                'viewer_email' => $this->view->viewer_email,
                'viewer_ip' => $this->view->viewer_ip,
                'device_type' => $this->view->device_type,
                'referrer' => $this->view->referrer,
                'country' => $this->view->country,
                'started_at' => $this->view->started_at?->toISOString(),
            ],
            'total_views' => $this->video->view_count,
            'unique_views' => $this->video->unique_view_count,
        ];
    }

    public function broadcastAs(): string
    {
        return 'video.viewed';
    }
}
