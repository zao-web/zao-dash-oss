<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ABTestGroup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'results' => 'array',
        'metadata' => 'array',
    ];

    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function hasWinner(): bool
    {
        return $this->winner_id !== null;
    }

    public function isStatisticallySignificant(): bool
    {
        // p-value < 0.05 means statistically significant
        return $this->confidence_level && $this->confidence_level < 0.05;
    }

    public function markAsCompleted(?int $winnerId = null): void
    {
        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
            'winner_id' => $winnerId,
        ]);
    }
}
