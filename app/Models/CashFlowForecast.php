<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashFlowForecast extends Model
{
    /** @use HasFactory<\Database\Factories\CashFlowForecastFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'forecast_date' => 'date',
        'projected_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'is_recurring' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personalAccount(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('forecast_date', '>=', now())
            ->orderBy('forecast_date');
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    public function scopeForDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('forecast_date', [$from, $to]);
    }

    public function scopeUnreconciled(Builder $query): Builder
    {
        return $query->where('forecast_date', '<', now())
            ->whereNull('actual_amount');
    }
}
