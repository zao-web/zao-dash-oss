<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IdealCustomerProfile extends Model
{
    protected $table = 'ideal_customer_profiles';

    protected $guarded = [];

    protected $casts = [
        'industries' => 'array',
        'company_sizes' => 'array',
        'locations' => 'array',
        'tech_stack' => 'array',
        'tools_used' => 'array',
        'buying_signals' => 'array',
        'pain_points' => 'array',
        'avg_deal_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'icp_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(OutreachCampaign::class, 'icp_id');
    }

    /**
     * Score a prospect against this ICP.
     */
    public function scoreProspect(array $prospectData): array
    {
        $breakdown = [];
        $totalScore = 0;

        // Industry match (0-100 scaled to weight)
        $industryScore = 0;
        if (! empty($this->industries) && ! empty($prospectData['industry'])) {
            $industryScore = in_array($prospectData['industry'], $this->industries) ? 100 : 0;
        }
        $breakdown['industry'] = [
            'score' => $industryScore,
            'weight' => $this->weight_industry,
            'weighted' => ($industryScore * $this->weight_industry) / 100,
        ];
        $totalScore += $breakdown['industry']['weighted'];

        // Company size match
        $sizeScore = 0;
        if (! empty($this->company_sizes) && ! empty($prospectData['company_size'])) {
            $sizeScore = in_array($prospectData['company_size'], $this->company_sizes) ? 100 : 0;
        }
        $breakdown['size'] = [
            'score' => $sizeScore,
            'weight' => $this->weight_size,
            'weighted' => ($sizeScore * $this->weight_size) / 100,
        ];
        $totalScore += $breakdown['size']['weighted'];

        // Tech stack match (partial matches allowed)
        $techScore = 0;
        if (! empty($this->tech_stack) && ! empty($prospectData['tech_stack'])) {
            $matches = count(array_intersect($this->tech_stack, $prospectData['tech_stack']));
            $techScore = min(100, ($matches / count($this->tech_stack)) * 100);
        }
        $breakdown['tech'] = [
            'score' => $techScore,
            'weight' => $this->weight_tech,
            'weighted' => ($techScore * $this->weight_tech) / 100,
        ];
        $totalScore += $breakdown['tech']['weighted'];

        // Buying signals match (partial matches)
        $signalScore = 0;
        if (! empty($this->buying_signals) && ! empty($prospectData['signals'])) {
            $matches = count(array_intersect($this->buying_signals, $prospectData['signals']));
            $signalScore = min(100, ($matches / count($this->buying_signals)) * 100);
        }
        $breakdown['signals'] = [
            'score' => $signalScore,
            'weight' => $this->weight_signals,
            'weighted' => ($signalScore * $this->weight_signals) / 100,
        ];
        $totalScore += $breakdown['signals']['weighted'];

        return [
            'total_score' => round($totalScore),
            'breakdown' => $breakdown,
            'is_qualified' => $totalScore >= 60,
        ];
    }

    /**
     * Scope: Active ICPs only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get prospect counts by status.
     */
    public function getProspectStatsAttribute(): array
    {
        return [
            'total' => $this->prospects()->count(),
            'new' => $this->prospects()->where('status', 'new')->count(),
            'qualified' => $this->prospects()->where('status', 'qualified')->count(),
            'converted' => $this->prospects()->where('status', 'converted')->count(),
        ];
    }
}
