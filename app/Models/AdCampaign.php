<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AdCampaign extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'targeting_config' => 'array',
        'pause_threshold' => 'array',
        'performance_goal' => 'array',
        'automation_enabled' => 'boolean',
        'approved_at' => 'datetime',
        'synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function metaAdAccount(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class);
    }

    public function abTestGroups(): HasMany
    {
        return $this->hasMany(ABTestGroup::class);
    }

    public function createdByAgentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'created_by_agent_run_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
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

    public function isAutomated(): bool
    {
        return $this->automation_enabled;
    }

    public function hasReachedGoal(): bool
    {
        if (! $this->performance_goal) {
            return false;
        }

        $latestPerformance = $this->performance()->latest('date')->first();
        if (! $latestPerformance) {
            return false;
        }

        if (isset($this->performance_goal['target_cpa']) && $latestPerformance->cpa) {
            return $latestPerformance->cpa <= $this->performance_goal['target_cpa'];
        }

        if (isset($this->performance_goal['target_roas']) && $latestPerformance->roas) {
            return $latestPerformance->roas >= $this->performance_goal['target_roas'];
        }

        return false;
    }

    public function shouldPause(): bool
    {
        if (! $this->pause_threshold || ! $this->automation_enabled) {
            return false;
        }

        $latestPerformance = $this->performance()->latest('date')->first();
        if (! $latestPerformance) {
            return false;
        }

        if (isset($this->pause_threshold['cpa_max']) && $latestPerformance->cpa) {
            if ($latestPerformance->cpa > $this->pause_threshold['cpa_max']) {
                return true;
            }
        }

        if (isset($this->pause_threshold['ctr_min']) && $latestPerformance->ctr) {
            if ($latestPerformance->ctr < $this->pause_threshold['ctr_min']) {
                return true;
            }
        }

        return false;
    }
}
