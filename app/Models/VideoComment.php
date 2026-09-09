<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoComment extends Model
{
    use SoftDeletes;

    const TYPE_COMMENT = 'comment';

    const TYPE_MARKER = 'marker';

    const TYPE_REACTION = 'reaction';

    protected $fillable = [
        'video_id',
        'user_id',
        'parent_id',
        'content',
        'timestamp_seconds',
        'timestamp_formatted',
        'viewer_name',
        'viewer_email',
        'type',
        'is_approved',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'timestamp_seconds' => 'integer',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(VideoComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(VideoComment::class, 'parent_id');
    }

    /**
     * Get the commenter's display name.
     */
    public function getCommenterNameAttribute(): string
    {
        if ($this->user) {
            return $this->user->name;
        }

        return $this->viewer_name ?? 'Anonymous';
    }

    /**
     * Check if comment is from authenticated user.
     */
    public function getIsAuthenticatedAttribute(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Format timestamp as clickable link text.
     */
    public function getTimestampLinkAttribute(): ?string
    {
        if ($this->timestamp_seconds === null) {
            return null;
        }

        return $this->timestamp_formatted ?? $this->formatSeconds($this->timestamp_seconds);
    }

    /**
     * Format seconds to MM:SS or HH:MM:SS.
     */
    protected function formatSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Scope for approved comments.
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope for pending moderation.
     */
    public function scopePendingApproval($query)
    {
        return $query->where('is_approved', false);
    }

    /**
     * Scope for top-level comments only.
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope ordered by timestamp.
     */
    public function scopeOrderByTimestamp($query)
    {
        return $query->orderBy('timestamp_seconds');
    }

    /**
     * Approve the comment.
     */
    public function approve(User $approver): void
    {
        $this->update([
            'is_approved' => true,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }
}
