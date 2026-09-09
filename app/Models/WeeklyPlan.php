<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'week_starting' => 'date',
        'focus_areas' => 'array',
        'targets' => 'array',
        'week_results' => 'array',
        'revision_history' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public function strategicGoal(): BelongsTo
    {
        return $this->belongsTo(StrategicGoal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WeeklyPlanItem::class);
    }

    public function pendingItems(): HasMany
    {
        return $this->items()->where('status', 'pending');
    }

    public function completedItems(): HasMany
    {
        return $this->items()->where('status', 'completed');
    }

    public function agentItems(): HasMany
    {
        return $this->items()->where('owner_type', 'agent');
    }

    public function humanItems(): HasMany
    {
        return $this->items()->where('owner_type', 'human');
    }

    /**
     * Get or create the plan for a specific week.
     */
    public static function forWeek(Carbon $date, ?int $goalId = null): self
    {
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY);

        return static::firstOrCreate(
            ['week_starting' => $monday->toDateString()],
            [
                'strategic_goal_id' => $goalId,
                'status' => self::STATUS_DRAFT,
            ]
        );
    }

    /**
     * Get current week's plan.
     *
     * @param  bool  $excludeRejected  Whether to exclude rejected plans (default: true)
     */
    public static function current(bool $excludeRejected = true): ?self
    {
        $monday = now()->startOfWeek(Carbon::MONDAY);
        $query = static::where('week_starting', $monday->toDateString());

        if ($excludeRejected) {
            $query->where('status', '!=', self::STATUS_REJECTED);
        }

        return $query->first();
    }

    /**
     * Is this the current week?
     */
    public function getIsCurrentWeekAttribute(): bool
    {
        $monday = now()->startOfWeek(Carbon::MONDAY);

        return $this->week_starting->equalTo($monday);
    }

    /**
     * Week ending date (Sunday).
     */
    public function getWeekEndingAttribute(): Carbon
    {
        return $this->week_starting->copy()->endOfWeek(Carbon::SUNDAY);
    }

    /**
     * Human-readable week label.
     */
    public function getWeekLabelAttribute(): string
    {
        return $this->week_starting->format('M j').' - '.$this->week_ending->format('M j, Y');
    }

    /**
     * Progress percentage based on completed items.
     */
    public function getProgressPercentAttribute(): float
    {
        $total = $this->items()->count();
        if ($total === 0) {
            return 0;
        }

        $completed = $this->completedItems()->count();

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Approve the plan.
     */
    public function approve(User $user): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject the plan.
     * Deletes all items so a fresh plan can be created for this week.
     */
    public function reject(User $user, string $reason): void
    {
        // Delete all items - rejection means starting fresh
        $this->items()->delete();

        // Clear focus areas and targets too
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'focus_areas' => null,
            'targets' => null,
            'strategy_notes' => null,
        ]);
    }

    /**
     * Request revision with feedback for the agent.
     * Returns the new plan that will be created.
     */
    public function requestRevision(User $user, string $feedback): self
    {
        // Store revision in history
        $history = $this->revision_history ?? [];
        $history[] = [
            'version' => count($history) + 1,
            'requested_by' => $user->id,
            'requested_at' => now()->toIso8601String(),
            'feedback' => $feedback,
            'previous_focus_areas' => $this->focus_areas,
            'previous_targets' => $this->targets,
        ];

        // Mark current plan as needing revision
        $this->update([
            'status' => self::STATUS_REVISION_REQUESTED,
            'revision_history' => $history,
            'revision_feedback' => $feedback,
        ]);

        return $this;
    }

    /**
     * Check if plan can be revised.
     */
    public function canBeRevised(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_REVISION_REQUESTED,
        ]);
    }

    /**
     * Get the revision count.
     */
    public function getRevisionCountAttribute(): int
    {
        return count($this->revision_history ?? []);
    }

    /**
     * Activate the plan (after approval).
     */
    public function activate(): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \RuntimeException('Plan must be approved before activation');
        }

        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Complete the plan with results.
     */
    public function complete(array $results = []): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'week_results' => $results,
        ]);
    }

    /**
     * Add an action item to this plan.
     */
    public function addItem(array $data): WeeklyPlanItem
    {
        return $this->items()->create($data);
    }

    /**
     * Add an agent task item.
     */
    public function addAgentItem(string $agentSlug, string $action, array $data = []): WeeklyPlanItem
    {
        return $this->items()->create(array_merge([
            'action' => $action,
            'owner_type' => 'agent',
            'agent_slug' => $agentSlug,
        ], $data));
    }

    /**
     * Add a human task item.
     */
    public function addHumanItem(string $action, array $data = []): WeeklyPlanItem
    {
        return $this->items()->create(array_merge([
            'action' => $action,
            'owner_type' => 'human',
        ], $data));
    }
}
