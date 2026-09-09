<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskComment extends Model
{
    const TYPE_COMMENT = 'comment';

    const TYPE_STATUS_CHANGE = 'status_change';

    const TYPE_ASSIGNMENT = 'assignment';

    const TYPE_SYSTEM = 'system';

    const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    public function getGroupedReactionsAttribute(): array
    {
        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($group) => [
                'emoji' => $group->first()->emoji,
                'count' => $group->count(),
                'users' => $group->pluck('user.name')->toArray(),
                'user_ids' => $group->pluck('user_id')->toArray(),
            ])
            ->values()
            ->toArray();
    }

    public static function createComment(Task $task, int $userId, string $content): self
    {
        return self::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'type' => self::TYPE_COMMENT,
            'content' => $content,
        ]);
    }

    public static function logStatusChange(Task $task, ?int $userId, string $oldStatus, string $newStatus): self
    {
        return self::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'type' => self::TYPE_STATUS_CHANGE,
            'content' => "Changed status from {$oldStatus} to {$newStatus}",
            'metadata' => [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
        ]);
    }

    public static function logAssignment(
        Task $task,
        ?int $userId,
        ?int $oldAssigneeId,
        ?int $newAssigneeId,
        string $oldAssigneeType = 'user',
        string $newAssigneeType = 'user'
    ): self {
        $oldAssignee = $oldAssigneeId
            ? self::resolveAssigneeName($oldAssigneeId, $oldAssigneeType)
            : 'Unassigned';
        $newAssignee = $newAssigneeId
            ? self::resolveAssigneeName($newAssigneeId, $newAssigneeType)
            : 'Unassigned';

        return self::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'type' => self::TYPE_ASSIGNMENT,
            'content' => "Changed assignee from {$oldAssignee} to {$newAssignee}",
            'metadata' => [
                'old_assignee_id' => $oldAssigneeId,
                'old_assignee_type' => $oldAssigneeType,
                'new_assignee_id' => $newAssigneeId,
                'new_assignee_type' => $newAssigneeType,
            ],
        ]);
    }

    /**
     * Resolve an assignee name from any assignee type.
     */
    protected static function resolveAssigneeName(int $id, string $type): string
    {
        return match ($type) {
            'agent' => Agent::find($id)?->name ?? 'Unknown Agent',
            'client_contact' => ClientContact::find($id)?->name ?? 'Unknown Contact',
            default => User::find($id)?->name ?? 'Unknown User',
        };
    }

    /**
     * Create a comment synced from external platform
     */
    public static function createExternalComment(
        Task $task,
        string $content,
        string $externalId,
        string $platform,
        string $authorName,
        ?string $authorEmail = null,
        ?\DateTimeInterface $createdAt = null
    ): self {
        // Try to find local user by email
        $userId = null;
        if ($authorEmail) {
            $user = User::where('email', $authorEmail)->first();
            $userId = $user?->id;
        }

        return self::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'type' => self::TYPE_EXTERNAL,
            'content' => $content,
            'metadata' => [
                'external_id' => $externalId,
                'platform' => $platform,
                'author_name' => $authorName,
                'author_email' => $authorEmail,
                'synced_at' => now()->toIso8601String(),
            ],
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    /**
     * Check if external comment already exists
     */
    public static function externalCommentExists(string $externalId, string $platform): bool
    {
        return self::where('type', self::TYPE_EXTERNAL)
            ->whereJsonContains('metadata->external_id', $externalId)
            ->whereJsonContains('metadata->platform', $platform)
            ->exists();
    }
}
