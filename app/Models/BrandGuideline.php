<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandGuideline extends Model
{
    protected $guarded = [];

    protected $casts = [
        'primary_colors' => 'array',
        'secondary_colors' => 'array',
        'fonts' => 'array',
        'keywords_to_include' => 'array',
        'keywords_to_avoid' => 'array',
        'competitor_urls' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function adCreatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primary_colors[0] ?? null;
    }

    public function getPrimaryFont(): ?string
    {
        return $this->fonts['primary'] ?? null;
    }

    public function hasLogo(): bool
    {
        return ! empty($this->logo_url);
    }

    public function shouldIncludeKeyword(string $keyword): bool
    {
        if (! $this->keywords_to_include) {
            return false;
        }

        foreach ($this->keywords_to_include as $include) {
            if (stripos($keyword, $include) !== false) {
                return true;
            }
        }

        return false;
    }

    public function shouldAvoidKeyword(string $keyword): bool
    {
        if (! $this->keywords_to_avoid) {
            return false;
        }

        foreach ($this->keywords_to_avoid as $avoid) {
            if (stripos($keyword, $avoid) !== false) {
                return true;
            }
        }

        return false;
    }
}
