<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RfpLearningInsight extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'confidence' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByImpactArea(Builder $query, string $area): Builder
    {
        return $query->where('impact_area', $area);
    }
}
