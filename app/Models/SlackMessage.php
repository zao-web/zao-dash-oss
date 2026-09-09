<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackMessage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'attachments' => 'array',
        'user_is_external' => 'boolean',
        'has_action_item' => 'boolean',
        'action_item_confidence' => 'decimal:2',
        'is_repeated_request' => 'boolean',
        'processed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SlackWorkspace::class, 'workspace_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SlackChannel::class, 'channel_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isThreadReply(): bool
    {
        return $this->thread_ts !== null && $this->thread_ts !== $this->message_ts;
    }

    public function isFromExternalUser(): bool
    {
        return $this->user_is_external;
    }

    public function needsProcessing(): bool
    {
        return $this->processed_at === null;
    }

    public function getPermalinkAttribute(): string
    {
        $workspace = $this->workspace;
        $channel = $this->channel;

        return "https://slack.com/archives/{$channel->channel_id}/p".
            str_replace('.', '', $this->message_ts);
    }
}
