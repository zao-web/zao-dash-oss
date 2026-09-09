<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class GitHubInstallation extends Model
{
    use HasFactory;

    protected $table = 'github_installations';

    protected $guarded = [];

    protected $casts = [
        'permissions' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function repos(): HasMany
    {
        return $this->hasMany(GitHubRepo::class, 'installation_id');
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function tokenIsExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }

    public function isOrgInstallation(): bool
    {
        return $this->account_type === 'Organization';
    }
}
