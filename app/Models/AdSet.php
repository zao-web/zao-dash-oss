<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AdSet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'targeting' => 'array',
        'attribution_setting' => 'array',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
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

    public function getAverageCPA(): ?float
    {
        $performance = $this->performance()
            ->whereNotNull('cpa')
            ->avg('cpa');

        return $performance ? (float) $performance : null;
    }

    public function getAverageCTR(): ?float
    {
        $performance = $this->performance()
            ->where('ctr', '>', 0)
            ->avg('ctr');

        return $performance ? (float) $performance : null;
    }
}
