<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HarvestTaskCategory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'default_hourly_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'harvest_task_id', 'harvest_id');
    }

    public function getTotalHoursAttribute(): float
    {
        return $this->timeEntries()->sum('hours');
    }
}
