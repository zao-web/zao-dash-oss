<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Debt extends Model
{
    /** @use HasFactory<\Database\Factories\DebtFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'minimum_payment' => 'decimal:2',
        'payment_due_day' => 'integer',
        'metadata' => 'array',
        'secured' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personalAccount(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    public function taxObligation(): HasOne
    {
        return $this->hasOne(TaxObligation::class);
    }

    public function collectionsAccount(): HasOne
    {
        return $this->hasOne(CollectionsAccount::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FinancialDocument::class);
    }

    public function cashFlowForecasts(): HasMany
    {
        return $this->hasMany(CashFlowForecast::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('debt_type', $type);
    }

    public function scopeHighPriority(Builder $query): Builder
    {
        return $query->where('priority', 'high');
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeWithEnforcement(Builder $query): Builder
    {
        return $query->whereNotNull('enforcement_status')
            ->where('enforcement_status', '!=', 'none');
    }
}
