<?php

namespace App\Events;

use App\Models\AdCampaign;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when creative generation completes for a campaign
 */
class CampaignCreativeGenerationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AdCampaign $campaign,
        public int $creativesGenerated,
        public bool $success,
        public ?string $errorMessage = null,
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
        return 'creative.generation.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'creatives_generated' => $this->creativesGenerated,
            'success' => $this->success,
            'error_message' => $this->errorMessage,
            'status' => $this->success ? 'completed' : 'failed',
            'message' => $this->success
                ? "Successfully generated {$this->creativesGenerated} ad creatives!"
                : "Creative generation failed: {$this->errorMessage}",
            'metadata' => $this->metadata,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
