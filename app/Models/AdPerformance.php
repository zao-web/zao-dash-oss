<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdPerformance extends Model
{
    protected $table = 'ad_performance';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function performable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Calculate CTR (Click-Through Rate)
     */
    public function calculateCTR(): float
    {
        if ($this->impressions == 0) {
            return 0;
        }

        return round(($this->clicks / $this->impressions) * 100, 4);
    }

    /**
     * Calculate CPC (Cost Per Click)
     */
    public function calculateCPC(): float
    {
        if ($this->clicks == 0) {
            return 0;
        }

        return round($this->spend / $this->clicks, 2);
    }

    /**
     * Calculate CPA (Cost Per Acquisition/Conversion)
     */
    public function calculateCPA(): ?float
    {
        if ($this->conversions == 0) {
            return null;
        }

        return round($this->spend / $this->conversions, 2);
    }

    /**
     * Calculate ROAS (Return on Ad Spend)
     */
    public function calculateROAS(): ?float
    {
        if ($this->spend == 0) {
            return null;
        }

        return round($this->conversion_value / $this->spend, 2);
    }

    /**
     * Update computed metrics
     */
    public function updateComputedMetrics(): void
    {
        $this->ctr = $this->calculateCTR();
        $this->cpc = $this->calculateCPC();
        $this->cpa = $this->calculateCPA();
        $this->roas = $this->calculateROAS();
        $this->save();
    }
}
