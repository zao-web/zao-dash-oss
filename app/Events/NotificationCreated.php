<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new Channel('notifications'), // Public channel for all users
        ];

        // If notification is for specific user, also broadcast on private channel
        if ($this->notification->user_id) {
            $channels[] = new PrivateChannel('notifications.'.$this->notification->user_id);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'message' => $this->notification->message,
            'icon' => $this->notification->icon,
            'severity' => $this->notification->severity,
            'action_url' => $this->notification->action_url,
            'action_label' => $this->notification->action_label,
            'is_read' => false,
            'created_at' => $this->notification->created_at->diffForHumans(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }
}
