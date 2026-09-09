<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialGoal extends Model
{
    /** @use HasFactory<\Database\Factories\FinancialGoalFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'target_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class, 'linked_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeByType(Builder $query, string $goalType): Builder
    {
        return $query->where('goal_type', $goalType);
    }

    public function scopeAchieved(Builder $query): Builder
    {
        return $query->where('status', 'achieved');
    }

    /**
     * Percentage of goal completed (0-100+).
     */
    public function percentComplete(): float
    {
        if ((float) $this->target_amount <= 0) {
            return 0;
        }

        return round(((float) $this->current_amount / (float) $this->target_amount) * 100, 1);
    }
}
