<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedInCredential extends Model
{
    protected $fillable = [
        'user_id',
        'linkedin_id',
        'name',
        'email',
        'profile_url',
        'profile_picture',
        'headline',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'organization_id',
        'organization_name',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'scopes' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function hasOrganizationAccess(): bool
    {
        return ! empty($this->organization_id);
    }
}
