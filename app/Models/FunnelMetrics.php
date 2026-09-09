<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FunnelMetrics extends Model
{
    protected $table = 'funnel_metrics';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'new_to_qualified_rate' => 'decimal:2',
        'qualified_to_proposal_rate' => 'decimal:2',
        'proposal_to_negotiation_rate' => 'decimal:2',
        'negotiation_to_won_rate' => 'decimal:2',
        'overall_win_rate' => 'decimal:2',
        'avg_deal_size' => 'decimal:2',
        'revenue_won' => 'decimal:2',
        'revenue_lost' => 'decimal:2',
        'pipeline_value' => 'decimal:2',
        'weighted_pipeline' => 'decimal:2',
    ];

    public const TYPE_DAILY = 'daily';

    public const TYPE_WEEKLY = 'weekly';

    public const TYPE_MONTHLY = 'monthly';

    /**
     * Calculate overall funnel conversion rate.
     */
    public function getOverallConversionAttribute(): float
    {
        if (! $this->new_to_qualified_rate) {
            return 0;
        }

        return ($this->new_to_qualified_rate / 100)
            * ($this->qualified_to_proposal_rate / 100)
            * ($this->proposal_to_negotiation_rate / 100)
            * ($this->negotiation_to_won_rate / 100)
            * 100;
    }

    /**
     * Win/Loss ratio.
     */
    public function getWinLossRatioAttribute(): ?float
    {
        if ($this->deals_lost <= 0) {
            return null;
        }

        return $this->deals_won / $this->deals_lost;
    }

    /**
     * Average revenue per deal (won only).
     */
    public function getAvgRevenuePerDealAttribute(): float
    {
        if ($this->deals_won <= 0) {
            return 0;
        }

        return $this->revenue_won / $this->deals_won;
    }

    /**
     * Scope: Get latest snapshot of a type.
     * Note: Named 'latestOfType' to avoid conflict with Laravel's built-in latest() method.
     */
    public function scopeLatestOfType(Builder $query, string $type = self::TYPE_WEEKLY): Builder
    {
        return $query->where('period_type', $type)->orderByDesc('period_end');
    }

    /**
     * Scope: For a date range.
     */
    public function scopeForRange(Builder $query, string $start, string $end): Builder
    {
        return $query->where('period_start', '>=', $start)->where('period_end', '<=', $end);
    }

    /**
     * Scope: By period type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('period_type', $type);
    }

    /**
     * Get average conversion rates over multiple snapshots.
     */
    public static function averageRates(string $type, int $periods = 12): array
    {
        $metrics = static::where('period_type', $type)
            ->orderByDesc('period_end')
            ->limit($periods)
            ->get();

        if ($metrics->isEmpty()) {
            return [
                'new_to_qualified_rate' => 0,
                'qualified_to_proposal_rate' => 0,
                'proposal_to_negotiation_rate' => 0,
                'negotiation_to_won_rate' => 0,
                'overall_win_rate' => 0,
                'avg_deal_size' => 0,
                'avg_sales_cycle_days' => 0,
            ];
        }

        return [
            'new_to_qualified_rate' => $metrics->avg('new_to_qualified_rate'),
            'qualified_to_proposal_rate' => $metrics->avg('qualified_to_proposal_rate'),
            'proposal_to_negotiation_rate' => $metrics->avg('proposal_to_negotiation_rate'),
            'negotiation_to_won_rate' => $metrics->avg('negotiation_to_won_rate'),
            'overall_win_rate' => $metrics->avg('overall_win_rate'),
            'avg_deal_size' => $metrics->avg('avg_deal_size'),
            'avg_sales_cycle_days' => round($metrics->avg('avg_sales_cycle_days')),
        ];
    }

    /**
     * Calculate trend (improving or declining) for a metric.
     */
    public static function trend(string $metric, string $type = self::TYPE_WEEKLY, int $periods = 4): array
    {
        $snapshots = static::where('period_type', $type)
            ->orderByDesc('period_end')
            ->limit($periods)
            ->pluck($metric, 'period_end')
            ->reverse();

        if ($snapshots->count() < 2) {
            return ['direction' => 'stable', 'change_pct' => 0, 'values' => $snapshots->values()];
        }

        $first = $snapshots->first();
        $last = $snapshots->last();
        $changePct = $first > 0 ? (($last - $first) / $first) * 100 : 0;

        return [
            'direction' => $changePct > 5 ? 'improving' : ($changePct < -5 ? 'declining' : 'stable'),
            'change_pct' => round($changePct, 1),
            'values' => $snapshots->values(),
        ];
    }
}
