<?php

namespace App\Events;

use App\Models\Video;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranscriptionCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video
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
            'transcript_preview' => $this->video->transcript_preview,
            'transcript_language' => $this->video->transcript_language,
            'segment_count' => count($this->video->transcript_segments ?? []),
        ];
    }

    public function broadcastAs(): string
    {
        return 'transcription.completed';
    }
}
