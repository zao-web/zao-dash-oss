<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class PmConnection extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // Supported platforms
    public const PLATFORM_CLICKUP = 'clickup';

    public const PLATFORM_NOTION = 'notion';

    public const PLATFORM_ASANA = 'asana';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function taskSources(): HasMany
    {
        return $this->hasMany(ExternalTaskSource::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(PmSyncLog::class);
    }

    // Encrypted token accessors
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false; // No expiration = never expires (personal tokens)
        }

        return $this->token_expires_at->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeClickUp($query)
    {
        return $query->platform(self::PLATFORM_CLICKUP);
    }
}
