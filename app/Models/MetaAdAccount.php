<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaAdAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'metadata' => 'array',
        'is_sandbox' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
    ];

    /**
     * Get the access token (decrypt on access)
     */
    public function getAccessTokenAttribute($value): string
    {
        return decrypt($value);
    }

    /**
     * Set the access token (encrypt on save)
     */
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function adCampaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    public function adCreatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && now()->greaterThan($this->token_expires_at);
    }

    public function isSandbox(): bool
    {
        return $this->is_sandbox === true;
    }

    /**
     * Get the active sandbox account
     */
    public static function sandbox(): ?self
    {
        return self::where('status', 'active')
            ->where('is_sandbox', true)
            ->first();
    }

    /**
     * Get the active production account
     */
    public static function production(): ?self
    {
        return self::where('status', 'active')
            ->where('is_sandbox', false)
            ->first();
    }
}
