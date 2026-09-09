<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlackThreadContext extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conversation_history' => 'array',
            'extracted_intents' => 'array',
            'pending_actions' => 'array',
            'completed_actions' => 'array',
            'context_data' => 'array',
            'last_interaction_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SlackChannel::class, 'channel_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isExpired() && in_array($this->current_state, ['awaiting_response', 'processing']);
    }

    public function addToHistory(string $role, string $content): void
    {
        $history = $this->conversation_history ?? [];
        $history[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->update([
            'conversation_history' => $history,
            'last_interaction_at' => now(),
        ]);
    }

    public function addPendingAction(string $type, array $data): string
    {
        $id = \Illuminate\Support\Str::uuid()->toString();
        $pending = $this->pending_actions ?? [];
        $pending[] = [
            'id' => $id,
            'type' => $type,
            'data' => $data,
            'created_at' => now()->toIso8601String(),
        ];

        $this->update(['pending_actions' => $pending]);

        return $id;
    }

    public function markActionCompleted(string $actionId, array $result = []): void
    {
        $pending = $this->pending_actions ?? [];
        $completed = $this->completed_actions ?? [];

        $index = collect($pending)->search(fn ($a) => $a['id'] === $actionId);

        if ($index !== false) {
            $action = $pending[$index];
            $action['completed_at'] = now()->toIso8601String();
            $action['result'] = $result;
            $completed[] = $action;

            array_splice($pending, $index, 1);

            $this->update([
                'pending_actions' => $pending,
                'completed_actions' => $completed,
            ]);
        }
    }

    public function getRecentHistory(int $limit = 10): array
    {
        $history = $this->conversation_history ?? [];

        return array_slice($history, -$limit);
    }

    public static function findOrCreateForThread(SlackChannel $channel, string $threadTs, string $botUserId): self
    {
        return self::firstOrCreate(
            [
                'channel_id' => $channel->id,
                'thread_ts' => $threadTs,
            ],
            [
                'bot_user_id' => $botUserId,
                'conversation_history' => [],
                'current_state' => 'idle',
                'last_interaction_at' => now(),
                'expires_at' => now()->addHours(24),
            ]
        );
    }
}
