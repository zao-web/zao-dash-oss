<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NorthStarGoal extends Model
{
    /** @use HasFactory<\Database\Factories\NorthStarGoalFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_cost_estimate' => 'decimal:2',
            'imagery' => 'array',
            'target_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(NorthStarMilestone::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(NorthStarProgress::class);
    }

    /**
     * Scope to active goals only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Calculate overall progress as a weighted average of milestone completion percentages.
     */
    public function overallProgress(): float
    {
        $milestones = $this->milestones;

        if ($milestones->isEmpty()) {
            return 0.0;
        }

        $totalWeight = $milestones->sum('target_amount');

        if ($totalWeight <= 0) {
            return $milestones->avg(fn ($m) => $m->percentComplete());
        }

        $weightedSum = $milestones->sum(fn ($m) => $m->percentComplete() * (float) $m->target_amount);

        return $weightedSum / $totalWeight;
    }

    /**
     * Estimate completion date based on current velocity from progress snapshots.
     */
    public function estimatedCompletionDate(): ?Carbon
    {
        $snapshots = $this->progress()
            ->orderBy('snapshot_date', 'desc')
            ->limit(30)
            ->get();

        if ($snapshots->count() < 7) {
            return null;
        }

        $oldest = $snapshots->last();
        $newest = $snapshots->first();
        $daysBetween = $oldest->snapshot_date->diffInDays($newest->snapshot_date);

        if ($daysBetween < 1) {
            return null;
        }

        $progressPerDay = ($newest->overall_progress_percent - $oldest->overall_progress_percent) / $daysBetween;

        if ($progressPerDay <= 0) {
            return null;
        }

        $remainingProgress = 100 - $newest->overall_progress_percent;
        $daysRemaining = (int) ceil($remainingProgress / $progressPerDay);

        return now()->addDays($daysRemaining);
    }
}
