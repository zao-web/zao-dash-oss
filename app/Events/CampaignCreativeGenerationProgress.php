<?php

namespace App\Events;

use App\Models\AdCampaign;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast progress updates during creative generation
 */
class CampaignCreativeGenerationProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AdCampaign $campaign,
        public string $step,
        public string $message,
        public int $currentIndex,
        public int $totalCount,
        public ?array $metadata = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('meta-ads.campaign.'.$this->campaign->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'creative.generation.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'step' => $this->step,
            'message' => $this->message,
            'current' => $this->currentIndex,
            'total' => $this->totalCount,
            'progress_percentage' => round(($this->currentIndex / $this->totalCount) * 100),
            'metadata' => $this->metadata,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
