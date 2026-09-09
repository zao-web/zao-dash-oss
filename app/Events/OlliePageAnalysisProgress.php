<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OlliePageAnalysisProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $batchId,
        public int $completed,
        public int $total,
        public string $message,
        public ?string $currentPage = null,
        public ?string $pageStatus = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('ollie-analysis'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'page.analysis.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'batch_id' => $this->batchId,
            'completed' => $this->completed,
            'total' => $this->total,
            'progress' => $this->total > 0 ? round(($this->completed / $this->total) * 100) : 0,
            'message' => $this->message,
            'current_page' => $this->currentPage,
            'page_status' => $this->pageStatus,
        ];
    }
}
