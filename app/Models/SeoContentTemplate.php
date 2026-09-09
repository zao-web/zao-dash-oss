<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoContentTemplate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'avg_conversion_rate' => 'decimal:2',
        'pages_generated' => 'integer',
        'total_leads' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeHighPerforming($query)
    {
        return $query->where('avg_conversion_rate', '>', 1.0)
            ->orderByDesc('avg_conversion_rate');
    }
}
