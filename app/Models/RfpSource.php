<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfpSource extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'filters' => 'array',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function opportunities(): HasMany
    {
        return $this->hasMany(RfpOpportunity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDueForCheck(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        $rawExpression = match ($driver) {
            'pgsql' => "last_checked_at < NOW() - (check_frequency_minutes || ' minutes')::interval",
            default => "last_checked_at < datetime('now', '-' || check_frequency_minutes || ' minutes')",
        };

        return $query->where(function (Builder $q) use ($rawExpression) {
            $q->whereNull('last_checked_at')
                ->orWhereRaw($rawExpression);
        });
    }
}
