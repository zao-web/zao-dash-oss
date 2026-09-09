<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    protected $guarded = [];

    protected $casts = [
        'budget' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'budget_hours' => 'decimal:2',
        'budget_is_monthly' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Route model binding uses ID (globally unique) instead of slug.
     * Slugs are only unique per client (compound unique on client_id + slug).
     * For user-facing URLs, use the client-scoped route: /clients/{client}/projects/{project}
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function unarchive(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function externalTaskSources(): HasMany
    {
        return $this->hasMany(ExternalTaskSource::class);
    }

    public function harvestProject(): HasOne
    {
        return $this->hasOne(HarvestProject::class, 'harvest_id', 'harvest_project_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get all time entries for this project (direct + via HarvestProject).
     * Use this for calculating totals when Harvest-imported time entries
     * may only have harvest_project_id set, not project_id.
     */
    public function allTimeEntries()
    {
        // Direct time entries (native)
        $directIds = $this->timeEntries()->pluck('id');

        // Time entries via HarvestProject linkage (HarvestProject.project_id = this project)
        $harvestProjectIds = HarvestProject::where('project_id', $this->id)->pluck('id');
        $harvestIds = $harvestProjectIds->isNotEmpty()
            ? TimeEntry::whereIn('harvest_project_id', $harvestProjectIds)->pluck('id')
            : collect();

        return TimeEntry::whereIn('id', $directIds->merge($harvestIds)->unique());
    }

    public function githubRepos(): HasMany
    {
        return $this->hasMany(GitHubRepo::class);
    }

    public function slackChannel(): BelongsTo
    {
        return $this->belongsTo(SlackChannel::class);
    }

    /**
     * Get total hours logged on this project (including Harvest-linked entries).
     */
    public function getTotalHoursAttribute(): float
    {
        return $this->allTimeEntries()->sum('hours');
    }

    /**
     * Get hours logged this month.
     */
    public function getMonthlyHoursAttribute(): float
    {
        return $this->allTimeEntries()
            ->whereBetween('spent_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('hours');
    }

    /**
     * Get budget remaining (if budget set).
     */
    public function getBudgetRemainingAttribute(): ?float
    {
        if (! $this->budget) {
            return null;
        }

        return max(0, $this->budget - $this->total_hours);
    }

    /**
     * Get budget usage percentage.
     */
    public function getBudgetUsagePercentAttribute(): ?float
    {
        if (! $this->budget || $this->budget == 0) {
            return null;
        }

        return round(($this->total_hours / $this->budget) * 100, 1);
    }

    public function getProjectDurationDaysAttribute(): ?int
    {
        if (! $this->start_date || ! $this->end_date) {
            return null;
        }

        return $this->start_date->diffInDays($this->end_date);
    }

    public function getDaysElapsedAttribute(): ?int
    {
        if (! $this->start_date) {
            return null;
        }

        return max(0, $this->start_date->diffInDays(now(), false));
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->end_date) {
            return null;
        }

        return max(0, (int) now()->diffInDays($this->end_date, false));
    }

    /**
     * Get task completion percentage.
     */
    public function getProgressPercentAttribute(): int
    {
        $total = $this->tasks->count();

        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->tasks->where('status', 'completed')->count() / $total) * 100);
    }

    public function getTimelineStatusAttribute(): ?string
    {
        if (! $this->start_date || ! $this->end_date || $this->tasks->isEmpty()) {
            return null;
        }

        $durationDays = $this->project_duration_days;
        if ($durationDays <= 0) {
            return null;
        }

        $timeProgressPct = min(100, ($this->days_elapsed / $durationDays) * 100);
        $taskProgressPct = ($this->tasks->where('status', 'completed')->count() / $this->tasks->count()) * 100;

        $diff = $taskProgressPct - $timeProgressPct;

        if ($diff >= 10) {
            return 'ahead';
        }
        if ($diff >= -10) {
            return 'on_track';
        }
        if ($diff >= -25) {
            return 'at_risk';
        }

        return 'behind';
    }
}
