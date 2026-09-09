<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NorthStarMilestone extends Model
{
    /** @use HasFactory<\Database\Factories\NorthStarMilestoneFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'tracking_config' => 'array',
            'celebration' => 'array',
            'target_date' => 'date',
            'estimated_completion' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(NorthStarGoal::class, 'north_star_goal_id');
    }

    /**
     * Scope to pending milestones.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to in-progress milestones.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope to completed milestones.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to order by the `order` column.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /**
     * Calculate the percentage completion of this milestone.
     */
    public function percentComplete(): float
    {
        if ($this->status === 'completed') {
            return 100.0;
        }

        if (! $this->target_amount || (float) $this->target_amount <= 0) {
            return 0.0;
        }

        return min(100.0, ((float) $this->current_amount / (float) $this->target_amount) * 100);
    }

    /**
     * Determine if this milestone is on track based on projected completion vs target date.
     */
    public function isOnTrack(): bool
    {
        if ($this->status === 'completed') {
            return true;
        }

        if (! $this->target_date) {
            return true;
        }

        if ($this->estimated_completion) {
            return $this->estimated_completion->lte($this->target_date);
        }

        return now()->lt($this->target_date);
    }

    /**
     * Mark this milestone as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'completed_at' => now(),
            'status' => 'completed',
        ]);
    }
}
