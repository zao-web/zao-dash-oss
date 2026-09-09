<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SlackWorkspace extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'sync_client_dms' => 'boolean',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'user_access_token',
    ];

    public function channels(): HasMany
    {
        return $this->hasMany(SlackChannel::class, 'workspace_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SlackMessage::class, 'workspace_id');
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setUserAccessTokenAttribute($value): void
    {
        $this->attributes['user_access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getUserAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function monitoredChannels(): HasMany
    {
        return $this->channels()->where('monitoring_enabled', true);
    }

    public function clientChannels(): HasMany
    {
        return $this->channels()->where('classification', 'client');
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(SlackUserWatchlistItem::class, 'workspace_id');
    }
}
