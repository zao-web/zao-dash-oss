<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandHistory extends Model
{
    protected $table = 'command_history';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'executed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a command execution.
     */
    public static function log(
        string $commandId,
        string $commandType,
        ?string $query = null,
        ?array $metadata = null
    ): ?self {
        if (! auth()->check()) {
            return null;
        }

        return self::create([
            'user_id' => auth()->id(),
            'command_id' => $commandId,
            'command_type' => $commandType,
            'query' => $query,
            'metadata' => $metadata,
            'executed_at' => now(),
        ]);
    }

    /**
     * Get recent commands for the current user.
     */
    public static function recent(int $limit = 10): array
    {
        if (! auth()->check()) {
            return [];
        }

        return self::where('user_id', auth()->id())
            ->orderByDesc('executed_at')
            ->limit($limit)
            ->get()
            ->unique('command_id')
            ->values()
            ->map(fn ($h) => [
                'command_id' => $h->command_id,
                'command_type' => $h->command_type,
                'query' => $h->query,
                'executed_at' => $h->executed_at->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get frequent commands for the current user.
     */
    public static function frequent(int $limit = 5): array
    {
        if (! auth()->check()) {
            return [];
        }

        return self::where('user_id', auth()->id())
            ->selectRaw('command_id, command_type, COUNT(*) as count')
            ->groupBy('command_id', 'command_type')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($h) => [
                'command_id' => $h->command_id,
                'command_type' => $h->command_type,
                'count' => $h->count,
            ])
            ->toArray();
    }
}
