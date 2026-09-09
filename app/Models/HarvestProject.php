<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HarvestProject extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_billable' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'budget' => 'decimal:2',
        'budget_is_monthly' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'harvest_project_id', 'harvest_id');
    }

    public function getTotalHoursAttribute(): float
    {
        return $this->timeEntries()->sum('hours');
    }

    public function getBillableHoursAttribute(): float
    {
        return $this->timeEntries()->where('is_billable', true)->sum('hours');
    }

    public function getBudgetUsedPercentAttribute(): ?float
    {
        if (! $this->budget || $this->budget == 0) {
            return null;
        }

        return round(($this->total_hours / $this->budget) * 100, 1);
    }
}
