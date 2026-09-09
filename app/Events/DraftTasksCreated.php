<?php

namespace App\Events;

use App\Models\Video;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DraftTasksCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Video $video,
        public array $tasks
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
            'tasks' => collect($this->tasks)->map(fn ($task) => [
                'id' => $task->id,
                'name' => $task->name,
                'priority' => $task->priority,
                'status' => $task->status,
            ])->all(),
            'tasks_count' => count($this->tasks),
            'requires_review' => true,
        ];
    }

    public function broadcastAs(): string
    {
        return 'draft-tasks.created';
    }
}
