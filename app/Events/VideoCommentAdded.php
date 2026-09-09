<?php

namespace App\Events;

use App\Models\Video;
use App\Models\VideoComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoCommentAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video,
        public VideoComment $comment
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
            'comment' => [
                'id' => $this->comment->id,
                'content' => $this->comment->content,
                'timestamp_seconds' => $this->comment->timestamp_seconds,
                'timestamp_formatted' => $this->comment->timestamp_formatted,
                'commenter_name' => $this->comment->commenter_name,
                'is_authenticated' => $this->comment->is_authenticated,
                'is_approved' => $this->comment->is_approved,
                'type' => $this->comment->type,
            ],
            'requires_moderation' => ! $this->comment->is_approved,
        ];
    }

    public function broadcastAs(): string
    {
        return 'video-comment.added';
    }
}
