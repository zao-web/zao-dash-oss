<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class QuickBooksConnection extends Model
{
    use HasFactory;

    protected $table = 'quickbooks_connections';

    /**
     * Scope to active (non-expired) connections.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('sync_enabled', true)
            ->whereNotNull('access_token')
            ->where('refresh_token_expires_at', '>', now());
    }

    protected $guarded = [];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sync_enabled' => 'boolean',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(QboAccount::class, 'qbo_connection_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(QboTransaction::class, 'qbo_connection_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(QboInvoice::class, 'qbo_connection_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(QboCustomer::class, 'qbo_connection_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FinancialSnapshot::class, 'qbo_connection_id');
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = Crypt::encryptString($value);
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->access_token_expires_at->isPast();
    }

    public function isRefreshTokenExpiring(): bool
    {
        return $this->refresh_token_expires_at->subDays(7)->isPast();
    }

    public function needsTokenRefresh(): bool
    {
        return $this->access_token_expires_at->subMinutes(10)->isPast();
    }
}
