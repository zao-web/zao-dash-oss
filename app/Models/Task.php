<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'metadata' => 'array',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'estimate_approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Resolve the assignee model based on assignee_type.
     * Returns User, Agent, or ClientContact.
     */
    public function getResolvedAssigneeAttribute(): ?Model
    {
        if (! $this->assigned_to) {
            return null;
        }

        return match ($this->assignee_type) {
            'agent' => Agent::find($this->assigned_to),
            'client_contact' => ClientContact::find($this->assigned_to),
            default => User::find($this->assigned_to),
        };
    }

    /**
     * Get assignee display info (name, type, id) regardless of assignee_type.
     *
     * @return array{id: int, name: string, type: string}|null
     */
    public function getAssigneeInfoAttribute(): ?array
    {
        if (! $this->assigned_to) {
            return null;
        }

        $type = $this->assignee_type ?? 'user';

        // Use eager-loaded relationship for users to avoid extra queries
        if ($type === 'user' && $this->relationLoaded('assignee') && $this->assignee) {
            return [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'type' => 'user',
            ];
        }

        $resolved = $this->resolved_assignee;
        if (! $resolved) {
            return null;
        }

        return [
            'id' => $resolved->id,
            'name' => $resolved->name,
            'type' => $type,
        ];
    }

    /**
     * Check if the task is assigned to an agent.
     */
    public function isAssignedToAgent(): bool
    {
        return $this->assignee_type === 'agent' && $this->assigned_to !== null;
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at', 'desc');
    }

    public function externalMappings(): HasMany
    {
        return $this->hasMany(ExternalTaskMapping::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderBy('created_at', 'desc');
    }

    public function agentTasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    public function latestAgentTask()
    {
        return $this->hasOne(AgentTask::class)->latestOfMany();
    }

    public function isExternallyManaged(): bool
    {
        return $this->externalMappings()->exists();
    }

    public function hasActiveAgentTask(): bool
    {
        return $this->agentTasks()
            ->whereIn('status', [AgentTask::STATUS_PENDING, AgentTask::STATUS_RUNNING, AgentTask::STATUS_RETRY_QUEUED])
            ->exists();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Get the variance between estimated and actual hours.
     * Positive = under budget, Negative = over budget.
     */
    public function getEstimateVarianceAttribute(): ?float
    {
        if (! $this->estimated_hours || ! $this->actual_hours) {
            return null;
        }

        return round($this->estimated_hours - $this->actual_hours, 2);
    }

    /**
     * Get the variance as a percentage.
     * Positive = under budget, Negative = over budget.
     */
    public function getEstimateVariancePercentAttribute(): ?float
    {
        if (! $this->estimated_hours || ! $this->actual_hours) {
            return null;
        }

        return round(($this->estimate_variance / $this->estimated_hours) * 100, 1);
    }

    /**
     * Check if task is over budget.
     */
    public function isOverBudget(): bool
    {
        if (! $this->estimated_hours || ! $this->actual_hours) {
            return false;
        }

        return $this->actual_hours > $this->estimated_hours;
    }

    /**
     * Recalculate actual hours from time entries.
     */
    public function recalculateActualHours(): void
    {
        $actualHours = $this->timeEntries()->sum('hours');

        $this->update(['actual_hours' => $actualHours]);
    }
}
