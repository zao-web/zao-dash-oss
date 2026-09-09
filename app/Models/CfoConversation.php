<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CfoConversation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'messages' => 'array',
        'context_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addMessage(string $role, string $content): void
    {
        $messages = $this->messages ?? [];
        $messages[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toIso8601String(),
        ];
        $this->update(['messages' => $messages]);
    }

    public function getLastMessageAttribute(): ?array
    {
        $messages = $this->messages ?? [];

        return ! empty($messages) ? end($messages) : null;
    }
}
