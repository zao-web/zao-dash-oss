<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinancialAlert extends Model
{
    /** @use HasFactory<\Database\Factories\FinancialAlertFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'deadline' => 'datetime',
        'dollar_impact' => 'decimal:2',
        'dollar_cost_of_inaction' => 'decimal:2',
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related', 'related_model_type', 'related_model_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('alert_type', $type);
    }

    public function scopeBySeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    public function scopeHigh(Builder $query): Builder
    {
        return $query->where('severity', 'high');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'expired']);
    }

    public function acknowledge(): self
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        return $this;
    }

    public function resolve(): self
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->deadline && $this->deadline->isPast() && $this->status === 'open';
    }
}
