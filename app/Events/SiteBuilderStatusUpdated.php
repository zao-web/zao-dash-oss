<?php

namespace App\Events;

use App\Models\SiteBuilderProject;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteBuilderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SiteBuilderProject $project,
        public string $status,
        public ?string $phase = null,
        public ?string $phaseStatus = null,
        public int $phaseProgress = 0,
        public int $progress = 0,
        public ?string $error = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('site-builder.'.$this->project->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->project->id,
            'status' => $this->status,
            'phase' => $this->phase,
            'phase_status' => $this->phaseStatus,
            'phase_progress' => $this->phaseProgress,
            'progress' => $this->progress,
            'staging_url' => $this->project->staging_url,
            'production_url' => $this->project->production_url,
            'error' => $this->error,
        ];
    }
}
