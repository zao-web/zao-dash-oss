<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class VideoVersion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'video_id',
        'version_number',
        'storage_path',
        'storage_disk',
        'duration',
        'file_size',
        'width',
        'height',
        'trim_start_seconds',
        'trim_end_seconds',
        'status',
        'is_current',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'duration' => 'integer',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'trim_start_seconds' => 'float',
        'trim_end_seconds' => 'float',
        'is_current' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accessors

    public function getStreamUrlAttribute(): string
    {
        return "/api/videos/{$this->video->uuid}/versions/{$this->id}/stream";
    }

    public function getFormattedDurationAttribute(): string
    {
        if (! $this->duration) {
            return '0:00';
        }

        $hours = floor($this->duration / 3600);
        $minutes = floor(($this->duration % 3600) / 60);
        $seconds = $this->duration % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getTrimRangeAttribute(): ?string
    {
        if ($this->trim_start_seconds === null && $this->trim_end_seconds === null) {
            return null; // Original, untrimmed
        }

        $start = $this->formatSeconds($this->trim_start_seconds ?? 0);
        $end = $this->formatSeconds($this->trim_end_seconds);

        return "{$start} - {$end}";
    }

    public function getIsOriginalAttribute(): bool
    {
        return $this->version_number === 1;
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size ?? 0;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    // Methods

    /**
     * Activate this version as the current version.
     */
    public function activate(): void
    {
        // Deactivate all other versions
        self::where('video_id', $this->video_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        // Activate this version
        $this->is_current = true;
        $this->save();

        // Update parent video
        $this->video->update([
            'current_version' => $this->version_number,
            'duration' => $this->duration,
        ]);
    }

    /**
     * Get the full storage path for this version.
     */
    public function getStoragePath(): string
    {
        return $this->storage_path;
    }

    /**
     * Check if this version file exists in storage.
     */
    public function fileExists(): bool
    {
        return Storage::disk($this->storage_disk)->exists($this->storage_path);
    }

    /**
     * Delete the version file from storage.
     */
    public function deleteFile(): bool
    {
        if ($this->fileExists()) {
            return Storage::disk($this->storage_disk)->delete($this->storage_path);
        }

        return true;
    }

    /**
     * Format seconds to MM:SS or HH:MM:SS.
     */
    protected function formatSeconds(?float $seconds): string
    {
        if ($seconds === null) {
            return '0:00';
        }

        $totalSeconds = (int) $seconds;
        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $secs = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    // Scopes

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeForVideo($query, int $videoId)
    {
        return $query->where('video_id', $videoId);
    }
}
