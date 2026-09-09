<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RealEstateProperty extends Model
{
    /** @use HasFactory<\Database\Factories\RealEstatePropertyFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'purchase_date' => 'date',
        'land_value' => 'decimal:2',
        'building_value' => 'decimal:2',
        'fair_market_value' => 'decimal:2',
        'cost_segregation_done' => 'boolean',
        'depreciation_schedule' => 'array',
        'annual_depreciation' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'is_str' => 'boolean',
        'average_stay_days' => 'decimal:1',
        'material_participation_hours' => 'decimal:1',
        'rental_income_annual' => 'decimal:2',
        'rental_expenses_annual' => 'decimal:2',
        'opportunity_zone' => 'boolean',
        'oz_investment_date' => 'date',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('property_type', $type);
    }

    public function scopeShortTermRentals(Builder $query): Builder
    {
        return $query->where('is_str', true);
    }

    public function scopeOpportunityZone(Builder $query): Builder
    {
        return $query->where('opportunity_zone', true);
    }

    public function scopeWithCostSeg(Builder $query): Builder
    {
        return $query->where('cost_segregation_done', true);
    }

    /**
     * Get net rental income (income minus expenses).
     */
    public function getNetRentalIncomeAttribute(): float
    {
        return (float) $this->rental_income_annual - (float) $this->rental_expenses_annual;
    }

    /**
     * Get remaining depreciable basis.
     */
    public function getRemainingBasisAttribute(): float
    {
        return (float) $this->building_value - (float) $this->accumulated_depreciation;
    }

    /**
     * Check if property qualifies as STR with material participation.
     */
    public function getQualifiesAsStrLoopholeAttribute(): bool
    {
        return $this->is_str
            && $this->average_stay_days !== null
            && (float) $this->average_stay_days <= 7
            && (float) $this->material_participation_hours >= 100;
    }
}
