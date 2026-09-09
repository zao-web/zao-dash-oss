<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlackThread extends Model
{
    protected $guarded = [];

    protected $casts = [
        'participants' => 'array',
        'action_items_extracted' => 'array',
        'has_external_participant' => 'boolean',
        'last_reply_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SlackChannel::class, 'channel_id');
    }

    public function messages(): HasMany
    {
        return $this->channel->messages()
            ->where('thread_ts', $this->thread_ts)
            ->orderBy('message_ts');
    }

    public function needsSummarization(): bool
    {
        return $this->message_count >= 10 && empty($this->summary);
    }

    public function hasClientParticipant(): bool
    {
        return $this->has_external_participant;
    }
}
