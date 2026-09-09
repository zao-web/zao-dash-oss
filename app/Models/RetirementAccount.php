<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetirementAccount extends Model
{
    /** @use HasFactory<\Database\Factories\RetirementAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'ytd_contributions' => 'decimal:2',
        'employee_deferral_ytd' => 'decimal:2',
        'employer_match_ytd' => 'decimal:2',
        'annual_limit' => 'decimal:2',
        'tax_year' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('tax_year', $year);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('account_type', $type);
    }

    /**
     * Get remaining contribution room for the year.
     */
    public function getRemainingRoomAttribute(): float
    {
        return max(0, (float) $this->annual_limit - (float) $this->ytd_contributions);
    }

    /**
     * Get contribution utilization percentage.
     */
    public function getUtilizationPercentAttribute(): float
    {
        if ((float) $this->annual_limit === 0.0) {
            return 0;
        }

        return round(((float) $this->ytd_contributions / (float) $this->annual_limit) * 100, 1);
    }
}
