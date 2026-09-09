<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountSyncProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $step,
        public string $message,
        public int $completed,
        public int $total,
        public ?string $accountName = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'account.sync.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'step' => $this->step,
            'message' => $this->message,
            'completed' => $this->completed,
            'total' => $this->total,
            'progress' => $this->total > 0 ? round(($this->completed / $this->total) * 100) : 0,
            'account_name' => $this->accountName,
        ];
    }
}
