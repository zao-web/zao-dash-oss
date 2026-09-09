<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxObligation extends Model
{
    /** @use HasFactory<\Database\Factories\TaxObligationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'original_assessment' => 'decimal:2',
        'penalties_accrued' => 'decimal:2',
        'interest_accrued' => 'decimal:2',
        'installment_monthly' => 'decimal:2',
        'offer_amount' => 'decimal:2',
        'collection_statute_expiration' => 'date',
        'next_action_date' => 'date',
        'notice_history' => 'array',
        'lien_filed' => 'boolean',
        'levy_issued' => 'boolean',
        'cdp_requested' => 'boolean',
        'passport_certified' => 'boolean',
        'filing_compliance' => 'boolean',
    ];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FinancialDocument::class);
    }

    public function scopeByTaxType(Builder $query, string $type): Builder
    {
        return $query->where('tax_type', $type);
    }

    public function scopeByTaxYear(Builder $query, int $year): Builder
    {
        return $query->where('tax_year', $year);
    }

    public function scopeNeedsAction(Builder $query): Builder
    {
        return $query->whereNotNull('next_action_date')
            ->where('next_action_date', '<=', now()->addDays(7));
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolution_status', '!=', 'resolved');
    }

    public function scopeWithActiveLien(Builder $query): Builder
    {
        return $query->where('lien_filed', true);
    }

    public function scopeWithActiveLevy(Builder $query): Builder
    {
        return $query->where('levy_issued', true);
    }

    public function scopePassportCertified(Builder $query): Builder
    {
        return $query->where('passport_certified', true);
    }

    public function scopeNeedsFilingCompliance(Builder $query): Builder
    {
        return $query->where('filing_compliance', false);
    }

    /**
     * Total outstanding including penalties and interest.
     */
    public function getTotalOwedAttribute(): float
    {
        return (float) $this->original_assessment
            + (float) $this->penalties_accrued
            + (float) $this->interest_accrued;
    }
}
