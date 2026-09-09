<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPerformanceHistory extends Model
{
    use HasFactory;

    protected $table = 'seo_performance_history';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'avg_position' => 'decimal:2',
        'ctr' => 'decimal:2',
        'sessions' => 'integer',
        'conversions' => 'integer',
        'bounce_rate' => 'decimal:2',
        'avg_session_duration' => 'integer',
    ];

    public function seoPage(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class);
    }

    public function seoKeyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class);
    }
}
