<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SinkingFund extends Model
{
    /** @use HasFactory<\Database\Factories\SinkingFundFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'monthly_contribution' => 'decimal:2',
        'target_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(SinkingFundContribution::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['planning', 'saving', 'ready']);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Percentage of target funded (0-100+).
     */
    public function percentFunded(): float
    {
        if ((float) $this->target_amount <= 0) {
            return 0;
        }

        return round(((float) $this->current_amount / (float) $this->target_amount) * 100, 1);
    }

    /**
     * Remaining amount needed to reach target.
     */
    public function amountRemaining(): float
    {
        return max(0, (float) $this->target_amount - (float) $this->current_amount);
    }

    /**
     * Estimated months to fully fund at current contribution rate.
     */
    public function estimatedMonthsToFund(): ?int
    {
        if ((float) $this->monthly_contribution <= 0) {
            return null;
        }

        $remaining = $this->amountRemaining();
        if ($remaining <= 0) {
            return 0;
        }

        return (int) ceil($remaining / (float) $this->monthly_contribution);
    }
}
