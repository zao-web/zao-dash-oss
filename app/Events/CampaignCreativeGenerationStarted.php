<?php

namespace App\Events;

use App\Models\AdCampaign;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when creative generation starts for a campaign
 */
class CampaignCreativeGenerationStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AdCampaign $campaign,
        public int $totalCreatives,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('meta-ads.campaign.'.$this->campaign->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'creative.generation.started';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'total_creatives' => $this->totalCreatives,
            'status' => 'started',
            'message' => "Starting AI creative generation for {$this->totalCreatives} variations...",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
