<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Ad extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tracking_specs' => 'array',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }

    public function adCreative(): BelongsTo
    {
        return $this->belongsTo(AdCreative::class);
    }

    public function creativeVariants(): HasMany
    {
        return $this->hasMany(AdCreativeVariant::class);
    }

    public function performance(): MorphMany
    {
        return $this->morphMany(AdPerformance::class, 'performable');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function getLatestPerformance(): ?AdPerformance
    {
        return $this->performance()->latest('date')->first();
    }

    public function isWinner(AdCampaign $campaign): bool
    {
        $latestPerf = $this->getLatestPerformance();
        if (! $latestPerf || ! $latestPerf->cpa || $latestPerf->conversions < 30) {
            return false;
        }

        $targetCPA = $campaign->performance_goal['target_cpa'] ?? null;
        if (! $targetCPA) {
            return false;
        }

        return $latestPerf->cpa < ($targetCPA * 0.8); // 20% better than target
    }

    public function isLoser(): bool
    {
        $latestPerf = $this->getLatestPerformance();
        if (! $latestPerf) {
            return false;
        }

        // Spent >$20 with 0 conversions
        if ($latestPerf->spend > 20 && $latestPerf->conversions == 0) {
            return true;
        }

        // Frequency >5 (ad fatigue)
        if ($latestPerf->frequency && $latestPerf->frequency > 5) {
            return true;
        }

        return false;
    }
}
