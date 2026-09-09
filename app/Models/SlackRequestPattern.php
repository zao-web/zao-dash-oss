<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackRequestPattern extends Model
{
    protected $guarded = [];

    protected $casts = [
        'topic_embedding' => 'array',
        'messages' => 'array',
        'first_asked_at' => 'datetime',
        'last_asked_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_resolved' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getHealthImpact(): float
    {
        if ($this->is_resolved || $this->ask_count <= 1) {
            return 0;
        }

        // Each repeat beyond 1 reduces health score
        return min(($this->ask_count - 1) * 0.5, 2.0);
    }

    public function getSeverityLevel(): string
    {
        return match (true) {
            $this->ask_count >= 4 => 'critical',
            $this->ask_count >= 3 => 'high',
            $this->ask_count >= 2 => 'warning',
            default => 'normal',
        };
    }

    public function markResolved(): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }

    public function addMessage(string $messageTs): void
    {
        $messages = $this->messages ?? [];
        $messages[] = $messageTs;

        $this->update([
            'messages' => $messages,
            'ask_count' => count($messages),
            'last_asked_at' => now(),
        ]);
    }
}
