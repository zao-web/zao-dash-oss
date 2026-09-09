<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Video extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Use UUID for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    const STATUS_UPLOADING = 'uploading';

    const STATUS_PROCESSING = 'processing';

    const STATUS_READY = 'ready';

    const STATUS_FAILED = 'failed';

    // Transcript statuses
    const TRANSCRIPT_PENDING = 'pending';

    const TRANSCRIPT_PROCESSING = 'processing';

    const TRANSCRIPT_COMPLETED = 'completed';

    const TRANSCRIPT_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'project_id',
        'client_id',
        'task_id',
        'title',
        'folder_path',
        'description',
        'original_filename',
        'storage_path',
        'storage_disk',
        'duration',
        'file_size',
        'mime_type',
        'width',
        'height',
        'thumbnail_path',
        'share_token',
        'share_expires_at',
        'is_public',
        'password_hash',
        'status',
        'processing_error',
        'view_count',
        'unique_view_count',
        'recording_metadata',
        'has_versions',
        'current_version',
        'original_storage_path',
        'recorded_at',
        'transcript',
        'transcript_segments',
        'transcript_words',
        'transcript_language',
        'transcript_status',
        'ai_summary',
        'ai_action_items',
        'ai_suggested_title',
        'ai_processed_at',
    ];

    protected $casts = [
        'share_expires_at' => 'datetime',
        'is_public' => 'boolean',
        'duration' => 'integer',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'view_count' => 'integer',
        'unique_view_count' => 'integer',
        'recording_metadata' => 'array',
        'has_versions' => 'boolean',
        'current_version' => 'integer',
        'recorded_at' => 'datetime',
        'transcript_segments' => 'array',
        'transcript_words' => 'array',
        'ai_action_items' => 'array',
        'ai_processed_at' => 'datetime',
    ];

    protected $hidden = [
        'password_hash',
        'storage_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (Video $video) {
            if (empty($video->uuid)) {
                $video->uuid = (string) Str::uuid();
            }
            if (empty($video->share_token)) {
                $video->share_token = Str::random(32);
            }

            // Auto-populate from task context
            if ($video->task_id && $video->task) {
                $task = $video->task;

                // Set title from task name if not provided
                if (empty($video->title)) {
                    $video->title = $task->name;
                }

                // Inherit project/client from task
                if (empty($video->project_id) && $task->project_id) {
                    $video->project_id = $task->project_id;
                }
                if (empty($video->client_id) && $task->project?->client_id) {
                    $video->client_id = $task->project->client_id;
                }
            }

            // Auto-generate folder path from client/project
            if (empty($video->folder_path)) {
                $video->folder_path = $video->generateFolderPath();
            }
        });
    }

    /**
     * Generate folder path from client/project relationships.
     * Format: "Client Name/Project Name" or just "Client Name" or "Uncategorized"
     */
    public function generateFolderPath(): string
    {
        $parts = [];

        if ($this->client_id) {
            $client = $this->client ?? Client::find($this->client_id);
            if ($client) {
                $parts[] = Str::slug($client->name, ' ');
            }
        }

        if ($this->project_id) {
            $project = $this->project ?? Project::find($this->project_id);
            if ($project) {
                $parts[] = Str::slug($project->name, ' ');
            }
        }

        return ! empty($parts) ? implode('/', $parts) : 'Uncategorized';
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(VideoComment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VideoVersion::class)->orderBy('version_number', 'desc');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(VideoVersion::class)->where('is_current', true);
    }

    public function originalVersion(): HasOne
    {
        return $this->hasOne(VideoVersion::class)->where('version_number', 1);
    }

    /**
     * Get approved comments only.
     */
    public function approvedComments(): HasMany
    {
        return $this->comments()->approved()->topLevel()->orderByTimestamp();
    }

    /**
     * Get pending moderation comments.
     */
    public function pendingComments(): HasMany
    {
        return $this->comments()->pendingApproval()->topLevel();
    }

    // Scopes

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('share_expires_at')
                ->orWhere('share_expires_at', '>', now());
        });
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Accessors

    public function getIsExpiredAttribute(): bool
    {
        return $this->share_expires_at && $this->share_expires_at->isPast();
    }

    public function getIsPasswordProtectedAttribute(): bool
    {
        return ! empty($this->password_hash);
    }

    public function getShareUrlAttribute(): string
    {
        return url("/v/{$this->share_token}");
    }

    public function getEmbedUrlAttribute(): string
    {
        return url("/embed/{$this->share_token}");
    }

    public function getFormattedDurationAttribute(): string
    {
        if (! $this->duration) {
            return '0:00';
        }

        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;

            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    public function getStreamUrlAttribute(): string
    {
        return url("/api/videos/{$this->uuid}/stream");
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return url("/api/videos/{$this->uuid}/thumbnail");
    }

    /**
     * Get the effective recording timestamp (recorded_at or created_at).
     */
    public function getRecordingTimestampAttribute(): \Carbon\Carbon
    {
        return $this->recorded_at ?? $this->created_at;
    }

    /**
     * Get formatted timestamp with both relative and absolute time.
     * Returns: "36 minutes ago (2:45 PM)" or "Dec 15 (2:45 PM)"
     */
    public function getFormattedRecordedAtAttribute(): string
    {
        $timestamp = $this->recording_timestamp;
        $relative = $timestamp->diffForHumans();
        $absolute = $timestamp->isToday()
            ? $timestamp->format('g:i A')
            : ($timestamp->isCurrentYear()
                ? $timestamp->format('M j, g:i A')
                : $timestamp->format('M j, Y g:i A'));

        return "{$relative} ({$absolute})";
    }

    /**
     * Get just the relative time for compact display.
     */
    public function getRelativeTimeAttribute(): string
    {
        return $this->recording_timestamp->diffForHumans();
    }

    /**
     * Get just the absolute time for full display.
     */
    public function getAbsoluteTimeAttribute(): string
    {
        $timestamp = $this->recording_timestamp;

        return $timestamp->isCurrentYear()
            ? $timestamp->format('M j, Y \a\t g:i A')
            : $timestamp->format('M j, Y \a\t g:i A');
    }

    /**
     * Check if transcription is available.
     */
    public function getHasTranscriptAttribute(): bool
    {
        return $this->transcript_status === self::TRANSCRIPT_COMPLETED
            && ! empty($this->transcript);
    }

    /**
     * Get transcript preview (first 200 chars).
     */
    public function getTranscriptPreviewAttribute(): ?string
    {
        if (! $this->has_transcript) {
            return null;
        }

        return \Illuminate\Support\Str::limit($this->transcript, 200);
    }

    // Methods

    public function isViewableBy(?string $password = null): bool
    {
        if ($this->status !== self::STATUS_READY) {
            return false;
        }

        if (! $this->is_public) {
            return false;
        }

        if ($this->is_expired) {
            return false;
        }

        if ($this->is_password_protected && $password !== null) {
            return password_verify($password, $this->password_hash);
        }

        return ! $this->is_password_protected;
    }

    public function setPassword(?string $password): void
    {
        $this->password_hash = $password ? bcrypt($password) : null;
        $this->save();
    }

    public function incrementViewCount(bool $isNewUniqueViewer = false): void
    {
        $this->increment('view_count');

        if ($isNewUniqueViewer) {
            $this->increment('unique_view_count');
        }
    }

    public function recalculateUniqueViews(): void
    {
        $uniqueCount = $this->views()
            ->distinct('viewer_ip')
            ->count('viewer_ip');

        $this->update(['unique_view_count' => $uniqueCount]);
    }

    public function regenerateShareToken(): string
    {
        $this->share_token = Str::random(32);
        $this->save();

        return $this->share_token;
    }

    /**
     * Create initial version record for an existing video.
     * Call this when first trimming a video that has no versions.
     */
    public function createInitialVersion(): VideoVersion
    {
        // Store original path for backup
        if (! $this->original_storage_path) {
            $this->original_storage_path = $this->storage_path;
            $this->save();
        }

        return VideoVersion::create([
            'video_id' => $this->id,
            'version_number' => 1,
            'storage_path' => $this->storage_path,
            'storage_disk' => $this->storage_disk,
            'duration' => $this->duration,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'trim_start_seconds' => null,
            'trim_end_seconds' => null,
            'status' => VideoVersion::STATUS_READY,
            'is_current' => true,
            'created_by' => $this->user_id,
        ]);
    }

    /**
     * Get the next version number for this video.
     */
    public function getNextVersionNumber(): int
    {
        $maxVersion = $this->versions()->max('version_number') ?? 0;

        return $maxVersion + 1;
    }

    /**
     * Get the storage path for the active version.
     * Falls back to main storage_path if no versions exist.
     */
    public function getActiveStoragePath(): string
    {
        if ($this->has_versions && $this->currentVersion) {
            return $this->currentVersion->storage_path;
        }

        return $this->storage_path;
    }

    /**
     * Get the storage disk for the active version.
     */
    public function getActiveStorageDisk(): string
    {
        if ($this->has_versions && $this->currentVersion) {
            return $this->currentVersion->storage_disk;
        }

        return $this->storage_disk;
    }

    /**
     * Check if trimming is available (video must be ready).
     */
    public function canTrim(): bool
    {
        return $this->status === self::STATUS_READY && $this->duration > 0;
    }
}
