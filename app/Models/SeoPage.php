<?php

namespace App\Models;

use App\Enums\SeoPageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'last_optimized_at' => 'datetime',
        'generated_by_agent' => 'boolean',
        'impressions_30d' => 'integer',
        'clicks_30d' => 'integer',
        'avg_position_30d' => 'decimal:2',
        'ctr_30d' => 'decimal:2',
        'total_leads' => 'integer',
        'total_projects' => 'integer',
        'total_revenue' => 'decimal:2',
        'conversion_rate' => 'decimal:2',
        'optimization_count' => 'integer',
        'status' => SeoPageStatus::class,
        'generation_started_at' => 'datetime',
        'generation_completed_at' => 'datetime',
        'schema_valid' => 'boolean',
        'word_count' => 'integer',
        'humanization_score' => 'integer',
        'internal_links_count' => 'integer',
        'external_links_count' => 'integer',
        'priority' => 'integer',
        'proprietary_data_count' => 'integer',
        'featured_image_wordpress_id' => 'integer',
    ];

    public function keywords(): HasMany
    {
        return $this->hasMany(SeoKeyword::class);
    }

    public function performanceHistory(): HasMany
    {
        return $this->hasMany(SeoPerformanceHistory::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', SeoPageStatus::Published);
    }

    public function scopePublished($query)
    {
        return $query->where('status', SeoPageStatus::Published);
    }

    public function scopeGeneratedByAgent($query)
    {
        return $query->where('generated_by_agent', true);
    }

    public function scopeQueued($query)
    {
        return $query->where('status', SeoPageStatus::Queued)
            ->orderBy('priority')
            ->orderBy('created_at');
    }

    public function scopeHighPerforming($query)
    {
        return $query->where('conversion_rate', '>', 1.0)
            ->orderByDesc('total_revenue');
    }

    public function isUnderperforming(): bool
    {
        return $this->clicks_30d > 50 && $this->total_leads === 0;
    }

    public function needsMetaOptimization(): bool
    {
        return $this->avg_position_30d < 5 && $this->ctr_30d < 2;
    }

    /**
     * Check if the page has a featured image.
     */
    public function hasFeaturedImage(): bool
    {
        return ! empty($this->featured_image_wordpress_id);
    }

    /**
     * Check if the page has a CTA configured.
     */
    public function hasCta(): bool
    {
        return ! empty($this->cta_type);
    }
}
