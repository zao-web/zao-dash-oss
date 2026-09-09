<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCreative extends Model
{
    protected $guarded = [];

    protected $casts = [
        'carousel_items' => 'array',
        'generation_metadata' => 'array',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function metaAdAccount(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class);
    }

    public function brandGuideline(): BelongsTo
    {
        return $this->belongsTo(BrandGuideline::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AdCreativeVariant::class);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isCarousel(): bool
    {
        return $this->type === 'carousel';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function getGenerationCost(): ?float
    {
        return $this->generation_metadata['cost'] ?? null;
    }

    public function getGenerationModel(): ?string
    {
        return $this->generation_metadata['model'] ?? null;
    }
}
