<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SeoBrandReferenceImage extends Model
{
    /** @use HasFactory<\Database\Factories\SeoBrandReferenceImageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'style_attributes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope to only active images.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get the full storage URL for the image.
     */
    public function getUrlAttribute(): ?string
    {
        if (! $this->storage_path) {
            return null;
        }

        return Storage::disk('public')->url($this->storage_path);
    }

    /**
     * Get the base64 encoded image data for API use.
     */
    public function getBase64Data(): ?string
    {
        if (! $this->storage_path || ! Storage::disk('public')->exists($this->storage_path)) {
            return null;
        }

        return base64_encode(Storage::disk('public')->get($this->storage_path));
    }

    /**
     * Get the MIME type of the image.
     */
    public function getMimeType(): string
    {
        $extension = pathinfo($this->storage_path, PATHINFO_EXTENSION);

        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    /**
     * Get active reference images for a specific category.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SeoBrandReferenceImage>
     */
    public static function getForCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
            ->byCategory($category)
            ->get();
    }
}
