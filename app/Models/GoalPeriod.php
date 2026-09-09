<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalPeriod extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'revenue_target' => 'decimal:2',
        'revenue_actual' => 'decimal:2',
        'pipeline_target' => 'decimal:2',
        'pipeline_actual' => 'decimal:2',
        'variance_pct' => 'decimal:2',
        'notes' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_AHEAD = 'ahead';

    public const STATUS_BEHIND = 'behind';

    public const STATUS_CRITICAL = 'critical';

    public const STATUS_COMPLETED = 'completed';

    public const TYPE_YEARLY = 'yearly';

    public const TYPE_QUARTERLY = 'quarterly';

    public const TYPE_MONTHLY = 'monthly';

    public const TYPE_WEEKLY = 'weekly';

    public function strategicGoal(): BelongsTo
    {
        return $this->belongsTo(StrategicGoal::class);
    }

    /**
     * Is this period currently active?
     */
    public function getIsCurrentAttribute(): bool
    {
        $now = now()->toDateString();

        return $this->period_start->toDateString() <= $now
            && $this->period_end->toDateString() >= $now;
    }

    /**
     * Is this period in the past?
     */
    public function getIsPastAttribute(): bool
    {
        return $this->period_end->lt(now());
    }

    /**
     * Is this period in the future?
     */
    public function getIsFutureAttribute(): bool
    {
        return $this->period_start->gt(now());
    }

    /**
     * Percentage of period that has elapsed.
     */
    public function getElapsedPercentAttribute(): float
    {
        if ($this->is_future) {
            return 0;
        }
        if ($this->is_past) {
            return 100;
        }

        $total = $this->period_start->diffInDays($this->period_end);
        $elapsed = $this->period_start->diffInDays(now());

        return $total > 0 ? min(100, ($elapsed / $total) * 100) : 100;
    }

    /**
     * Revenue progress percentage.
     */
    public function getRevenueProgressAttribute(): float
    {
        if ($this->revenue_target <= 0) {
            return 0;
        }

        return min(100, ($this->revenue_actual / $this->revenue_target) * 100);
    }

    /**
     * Leads progress percentage.
     */
    public function getLeadsProgressAttribute(): float
    {
        if ($this->leads_target <= 0) {
            return 0;
        }

        return min(100, ($this->leads_actual / $this->leads_target) * 100);
    }

    /**
     * Deals progress percentage.
     */
    public function getDealsProgressAttribute(): float
    {
        if ($this->closed_deals_target <= 0) {
            return 0;
        }

        return min(100, ($this->closed_deals_actual / $this->closed_deals_target) * 100);
    }

    /**
     * Pipeline progress percentage.
     */
    public function getPipelineProgressAttribute(): float
    {
        if ($this->pipeline_target <= 0) {
            return 0;
        }

        return min(100, ($this->pipeline_actual / $this->pipeline_target) * 100);
    }

    /**
     * Calculate and update variance percentage.
     */
    public function calculateVariance(): float
    {
        if ($this->revenue_target <= 0) {
            $this->variance_pct = 0;
        } else {
            // Positive = ahead, negative = behind
            $this->variance_pct = (($this->revenue_actual - $this->revenue_target) / $this->revenue_target) * 100;
        }

        return $this->variance_pct;
    }

    /**
     * Update status based on progress vs elapsed time.
     */
    public function updateStatus(): string
    {
        if ($this->is_future) {
            $this->status = self::STATUS_PENDING;
        } elseif ($this->is_past) {
            $this->status = $this->revenue_actual >= $this->revenue_target
                ? self::STATUS_COMPLETED
                : self::STATUS_BEHIND;
        } else {
            // Current period - compare progress to elapsed time
            $expectedProgress = $this->elapsed_percent;
            $actualProgress = $this->revenue_progress;

            if ($actualProgress >= $expectedProgress * 1.1) {
                $this->status = self::STATUS_AHEAD;
            } elseif ($actualProgress >= $expectedProgress * 0.8) {
                $this->status = self::STATUS_ON_TRACK;
            } elseif ($actualProgress >= $expectedProgress * 0.5) {
                $this->status = self::STATUS_BEHIND;
            } else {
                $this->status = self::STATUS_CRITICAL;
            }
        }

        return $this->status;
    }

    /**
     * Scope: Current periods.
     */
    public function scopeCurrent($query)
    {
        $now = now()->toDateString();

        return $query->where('period_start', '<=', $now)->where('period_end', '>=', $now);
    }

    /**
     * Scope: Past periods.
     */
    public function scopePast($query)
    {
        return $query->where('period_end', '<', now());
    }

    /**
     * Scope: Future periods.
     */
    public function scopeFuture($query)
    {
        return $query->where('period_start', '>', now());
    }

    /**
     * Scope: By type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('period_type', $type);
    }
}
