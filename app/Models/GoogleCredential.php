<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GoogleCredential extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'watch_expiration' => 'datetime',
        'calendar_watch_expiration' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function needsWatchRenewal(): bool
    {
        if (! $this->watch_expiration) {
            return true;
        }

        // Renew 1 day before expiration
        return $this->watch_expiration->subDay()->isPast();
    }

    public function needsCalendarWatchRenewal(): bool
    {
        if (! $this->calendar_watch_expiration) {
            return true;
        }

        return $this->calendar_watch_expiration->subDay()->isPast();
    }
}
