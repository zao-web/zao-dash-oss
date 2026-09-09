<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SlackChannel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_private' => 'boolean',
        'is_shared' => 'boolean',
        'is_archived' => 'boolean',
        'is_dm' => 'boolean',
        'dm_user_ids' => 'array',
        'is_monitored' => 'boolean',
        'monitoring_enabled' => 'boolean',
        'sync_bot_messages' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SlackWorkspace::class, 'workspace_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the project linked to this channel.
     * A project can be directly linked via slack_channel_id.
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'slack_channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SlackMessage::class, 'channel_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(SlackThread::class, 'channel_id');
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(SlackUserWatchlistItem::class, 'slack_channel_id');
    }

    public function isClientChannel(): bool
    {
        return $this->classification === 'client' || $this->client_id !== null;
    }

    public function classifyAutomatically(): string
    {
        $name = strtolower($this->channel_name);

        if (str_contains($name, 'internal') || str_contains($name, 'team') || str_contains($name, 'xeo')) {
            return 'internal';
        }

        if ($this->is_shared || $this->client_id) {
            return 'client';
        }

        if (in_array($name, ['general', 'random'])) {
            return 'general';
        }

        return 'project';
    }
}
