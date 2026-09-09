<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class WebsiteProjectAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'website_project_id',
        'uploaded_by_user_id',
        'filename',
        'original_filename',
        'disk',
        'path',
        'mime_type',
        'size',
        'type',
        'category',
        'description',
        'metadata',
        'status',
        'processing_notes',
        'wordpress_media_id',
        'wordpress_url',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
        'wordpress_media_id' => 'integer',
    ];

    const TYPE_IMAGE = 'image';

    const TYPE_DOCUMENT = 'document';

    const TYPE_VIDEO = 'video';

    const TYPE_AUDIO = 'audio';

    const TYPE_OTHER = 'other';

    const CATEGORY_LOGO = 'logo';

    const CATEGORY_HERO = 'hero';

    const CATEGORY_BACKGROUND = 'background';

    const CATEGORY_BRIEF = 'brief';

    const CATEGORY_CONTENT = 'content';

    const CATEGORY_REFERENCE = 'reference';

    const CATEGORY_ICON = 'icon';

    const CATEGORY_PHOTO = 'photo';

    const STATUS_UPLOADED = 'uploaded';

    const STATUS_PROCESSING = 'processing';

    const STATUS_READY = 'ready';

    const STATUS_FAILED = 'failed';

    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function getUrl(): ?string
    {
        if ($this->wordpress_url) {
            return $this->wordpress_url;
        }

        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getContents(): ?string
    {
        try {
            return Storage::disk($this->disk)->get($this->path);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isDocument(): bool
    {
        return $this->type === self::TYPE_DOCUMENT;
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function getHumanReadableSize(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public static function determineType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return self::TYPE_IMAGE;
        }

        if (str_starts_with($mimeType, 'video/')) {
            return self::TYPE_VIDEO;
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return self::TYPE_AUDIO;
        }

        $documentMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'text/markdown',
        ];

        if (in_array($mimeType, $documentMimes)) {
            return self::TYPE_DOCUMENT;
        }

        return self::TYPE_OTHER;
    }

    public function scopeImages($query)
    {
        return $query->where('type', self::TYPE_IMAGE);
    }

    public function scopeDocuments($query)
    {
        return $query->where('type', self::TYPE_DOCUMENT);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function markAsReady(): bool
    {
        return $this->update(['status' => self::STATUS_READY]);
    }

    public function markAsFailed(string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'processing_notes' => $reason,
        ]);
    }

    public function setWordPressMedia(int $mediaId, string $url): bool
    {
        return $this->update([
            'wordpress_media_id' => $mediaId,
            'wordpress_url' => $url,
        ]);
    }

    public function toArrayForAgent(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_filename,
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'mime_type' => $this->mime_type,
            'size' => $this->getHumanReadableSize(),
            'local_path' => $this->getFullPath(),
            'wordpress_url' => $this->wordpress_url,
            'metadata' => $this->metadata,
        ];
    }
}
