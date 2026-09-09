<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoKeyword extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'estimated_monthly_volume' => 'integer',
        'difficulty_score' => 'integer',
        'current_position' => 'decimal:2',
        'best_position' => 'decimal:2',
        'first_tracked_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function seoPage(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class);
    }

    public function performanceHistory(): HasMany
    {
        return $this->hasMany(SeoPerformanceHistory::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCommercialIntent($query)
    {
        return $query->where('intent', 'transactional');
    }

    public function scopeWorthTargeting($query, int $maxDifficulty = 70)
    {
        return $query->where('difficulty_score', '<=', $maxDifficulty)
            ->where('estimated_monthly_volume', '>', 0)
            ->whereNull('seo_page_id');
    }

    public function isWorthTargeting(): bool
    {
        return $this->difficulty_score <= 70
            && $this->estimated_monthly_volume > 0
            && ! $this->seo_page_id;
    }
}
