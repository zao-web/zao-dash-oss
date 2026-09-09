<?php

namespace App\Agents\Tools;

use App\Services\BusinessIntelligenceService;
use Carbon\Carbon;

/**
 * Get funnel conversion metrics.
 *
 * Returns stage-to-stage conversion rates, win rates,
 * average deal size, and sales cycle length.
 */
class GetFunnelMetricsTool extends BaseTool
{
    public function __construct(
        protected BusinessIntelligenceService $intelligence
    ) {}

    public function category(): string
    {
        return 'analysis';
    }

    public function name(): string
    {
        return 'Get Funnel Metrics';
    }

    public function description(): string
    {
        return 'Get sales funnel conversion metrics including stage conversion rates, win rate, average deal size, and sales cycle duration.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['mtd', 'qtd', 'ytd', 'last_30', 'last_90', 'custom'],
                    'description' => 'Time period for metrics (default: last_90)',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Start date for custom period (YYYY-MM-DD)',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'End date for custom period (YYYY-MM-DD)',
                ],
                'include_trends' => [
                    'type' => 'boolean',
                    'description' => 'Include trends vs previous period',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'period' => 'nullable|in:mtd,qtd,ytd,last_30,last_90,custom',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'include_trends' => 'nullable|boolean',
        ];
    }

    public function execute(array $params): array
    {
        [$startDate, $endDate] = $this->resolveDates($params);

        $metrics = $this->intelligence->calculateFunnelMetrics($startDate, $endDate);

        $result = [
            'success' => true,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'label' => $params['period'] ?? 'last_90',
            ],
            'conversion_rates' => [
                'new_to_qualified' => $metrics['new_to_qualified_rate'].'%',
                'qualified_to_proposal' => $metrics['qualified_to_proposal_rate'].'%',
                'proposal_to_negotiation' => $metrics['proposal_to_negotiation_rate'].'%',
                'negotiation_to_won' => $metrics['negotiation_to_won_rate'].'%',
                'overall_win_rate' => $metrics['overall_win_rate'].'%',
            ],
            'deal_metrics' => [
                'avg_deal_size' => '$'.number_format($metrics['avg_deal_size']),
                'avg_sales_cycle_days' => $metrics['avg_sales_cycle_days'],
            ],
            'volume' => [
                'leads_created' => $metrics['leads_created'],
                'leads_qualified' => $metrics['leads_qualified'],
                'proposals_sent' => $metrics['proposals_sent'],
                'deals_won' => $metrics['deals_won'],
                'deals_lost' => $metrics['deals_lost'],
            ],
            'revenue' => [
                'won' => '$'.number_format($metrics['revenue_won']),
                'lost' => '$'.number_format($metrics['revenue_lost']),
            ],
            'pipeline' => [
                'total_value' => '$'.number_format($metrics['pipeline_value']),
                'weighted_value' => '$'.number_format($metrics['weighted_pipeline']),
                'by_stage' => $metrics['by_stage'],
            ],
        ];

        // Include trends if requested
        if (! empty($params['include_trends'])) {
            $result['trends'] = $this->calculateTrends($startDate, $endDate);
        }

        // Add interpretation
        $result['interpretation'] = $this->interpretMetrics($metrics);

        return $result;
    }

    protected function resolveDates(array $params): array
    {
        $period = $params['period'] ?? 'last_90';
        $now = now();

        return match ($period) {
            'mtd' => [$now->copy()->startOfMonth(), $now],
            'qtd' => [$now->copy()->startOfQuarter(), $now],
            'ytd' => [$now->copy()->startOfYear(), $now],
            'last_30' => [$now->copy()->subDays(30), $now],
            'last_90' => [$now->copy()->subDays(90), $now],
            'custom' => [
                Carbon::parse($params['start_date']),
                Carbon::parse($params['end_date'] ?? now()),
            ],
            default => [$now->copy()->subDays(90), $now],
        };
    }

    protected function calculateTrends(Carbon $currentStart, Carbon $currentEnd): array
    {
        $periodDays = $currentStart->diffInDays($currentEnd);
        $previousStart = $currentStart->copy()->subDays($periodDays);
        $previousEnd = $currentStart->copy()->subDay();

        $previousMetrics = $this->intelligence->calculateFunnelMetrics($previousStart, $previousEnd);
        $currentMetrics = $this->intelligence->calculateFunnelMetrics($currentStart, $currentEnd);

        return [
            'previous_period' => $previousStart->toDateString().' to '.$previousEnd->toDateString(),
            'win_rate_change' => round($currentMetrics['overall_win_rate'] - $previousMetrics['overall_win_rate'], 1).'%',
            'deal_size_change' => round((($currentMetrics['avg_deal_size'] - $previousMetrics['avg_deal_size']) / max(1, $previousMetrics['avg_deal_size'])) * 100, 1).'%',
            'cycle_change' => $currentMetrics['avg_sales_cycle_days'] - $previousMetrics['avg_sales_cycle_days'].' days',
            'volume_change' => round((($currentMetrics['leads_created'] - $previousMetrics['leads_created']) / max(1, $previousMetrics['leads_created'])) * 100, 1).'%',
        ];
    }

    protected function interpretMetrics(array $metrics): array
    {
        $insights = [];

        // Win rate
        if ($metrics['overall_win_rate'] < 20) {
            $insights[] = 'Win rate is low (<20%). Review qualification criteria and proposal quality.';
        } elseif ($metrics['overall_win_rate'] > 40) {
            $insights[] = 'Strong win rate (>40%). Consider raising prices or targeting larger deals.';
        }

        // Sales cycle
        if ($metrics['avg_sales_cycle_days'] > 60) {
            $insights[] = 'Long sales cycle (>60 days). Look for bottlenecks in the process.';
        }

        // Conversion bottlenecks
        $rates = [
            'new_to_qualified' => $metrics['new_to_qualified_rate'],
            'qualified_to_proposal' => $metrics['qualified_to_proposal_rate'],
            'proposal_to_negotiation' => $metrics['proposal_to_negotiation_rate'],
            'negotiation_to_won' => $metrics['negotiation_to_won_rate'],
        ];

        $lowestRate = min($rates);
        $lowestStage = array_search($lowestRate, $rates);

        if ($lowestRate < 50) {
            $insights[] = "Bottleneck detected at {$lowestStage} ({$lowestRate}%). Focus improvement efforts here.";
        }

        // Pipeline health
        if ($metrics['pipeline_value'] < $metrics['revenue_won'] * 2) {
            $insights[] = 'Pipeline coverage is thin (<2x). Prioritize lead generation.';
        }

        return $insights ?: ['Metrics look healthy. Maintain current performance.'];
    }
}
