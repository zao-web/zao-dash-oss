<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    /** @use HasFactory<\Database\Factories\TimeEntryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'cost_rate' => 'decimal:2',
            'spent_date' => 'date',
            'is_running' => 'boolean',
            'is_billable' => 'boolean',
            'is_billed' => 'boolean',
            'timer_started_at' => 'datetime',
            'external_reference' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function harvestProject(): BelongsTo
    {
        return $this->belongsTo(HarvestProject::class, 'harvest_project_id');
    }

    public function getBillableAmountAttribute(): float
    {
        if (! $this->is_billable || ! $this->hourly_rate) {
            return 0;
        }

        return $this->hours * $this->hourly_rate;
    }

    public function getCostAttribute(): float
    {
        if (! $this->cost_rate) {
            return 0;
        }

        return $this->hours * $this->cost_rate;
    }

    public function isRunning(): bool
    {
        return $this->is_running;
    }
}
