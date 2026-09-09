<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBudget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'budget_hours' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'hours_logged' => 'decimal:2',
        'amount_billed' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_activity_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getBudgetUsedPercentAttribute(): float
    {
        if (! $this->budget_hours || $this->budget_hours == 0) {
            return 0;
        }

        return round(($this->hours_logged / $this->budget_hours) * 100, 1);
    }

    public function getRemainingHoursAttribute(): float
    {
        if (! $this->budget_hours) {
            return 0;
        }

        return max(0, $this->budget_hours - $this->hours_logged);
    }

    public function isOverBudget(): bool
    {
        return $this->budget_hours && $this->hours_logged > $this->budget_hours;
    }

    public function isAtRisk(): bool
    {
        return $this->budget_used_percent >= 80 && ! $this->isOverBudget();
    }

    public function updateStatus(): void
    {
        $status = 'on_track';

        if ($this->isOverBudget()) {
            $status = 'over_budget';
        } elseif ($this->isAtRisk()) {
            $status = 'at_risk';
        }

        $this->update(['status' => $status]);
    }
}
