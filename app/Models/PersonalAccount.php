<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PersonalAccount extends Model
{
    /** @use HasFactory<\Database\Factories\PersonalAccountFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'is_business' => 'boolean',
        'is_closed' => 'boolean',
        'inactive_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PersonalTransaction::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function cashFlowForecasts(): HasMany
    {
        return $this->hasMany(CashFlowForecast::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_closed', false);
    }

    public function scopeWithoutInactive(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.inactive_at');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('account_type', $type);
    }

    public function scopeBusiness(Builder $query): Builder
    {
        return $query->where('is_business', true);
    }

    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('is_business', false);
    }

    public function isPlaidMapped(): bool
    {
        return is_string($this->plaid_account_id)
            && $this->plaid_account_id !== ''
            && ! str_starts_with($this->plaid_account_id, 'teller_');
    }

    public function isTellerMapped(): bool
    {
        if ($this->isPlaidMapped()) {
            return false;
        }

        return ($this->metadata['provider'] ?? null) === 'teller'
            || (is_string($this->plaid_account_id) && str_starts_with($this->plaid_account_id, 'teller_'));
    }

    public function syncProvider(): ?string
    {
        if ($this->isPlaidMapped()) {
            return 'plaid';
        }

        if ($this->isTellerMapped()) {
            return 'teller';
        }

        return null;
    }
}
