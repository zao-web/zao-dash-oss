<?php

namespace App\Agents\Tools;

use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;

/**
 * Forecast future revenue.
 *
 * Projects revenue using velocity and pipeline-based methods
 * for 30, 60, 90 days and year-end.
 */
class ForecastRevenueTool extends BaseTool
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
        return 'Forecast Revenue';
    }

    public function description(): string
    {
        return 'Forecast revenue for upcoming periods using velocity and pipeline-based projections. Includes 30/60/90 day and year-end forecasts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'goal_id' => [
                    'type' => 'integer',
                    'description' => 'ID of strategic goal (defaults to active goal)',
                ],
                'horizon_days' => [
                    'type' => 'integer',
                    'description' => 'Forecast horizon in days (default: 90)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'goal_id' => 'nullable|integer|exists:strategic_goals,id',
            'horizon_days' => 'nullable|integer|min:7|max:365',
        ];
    }

    public function execute(array $params): array
    {
        // Get goal
        if (! empty($params['goal_id'])) {
            $goal = StrategicGoal::find($params['goal_id']);
        } else {
            $goal = StrategicGoal::where('status', 'active')->latest()->first();
        }

        if (! $goal) {
            return [
                'success' => false,
                'error' => 'no_active_goal',
                'message' => 'No strategic goal found.',
            ];
        }

        $horizon = $params['horizon_days'] ?? 90;
        $forecast = $this->intelligence->forecastRevenue($goal, $horizon);

        return [
            'success' => true,
            'goal' => [
                'id' => $goal->id,
                'fiscal_year' => $goal->fiscal_year,
                'revenue_target' => '$'.number_format($goal->revenue_target),
            ],
            'current_state' => [
                'revenue_actual' => '$'.number_format($forecast['current_revenue']),
                'weighted_pipeline' => '$'.number_format($forecast['weighted_pipeline']),
                'daily_velocity' => '$'.number_format($forecast['daily_velocity']).'/day',
            ],
            'forecasts' => [
                '30_day' => [
                    'velocity' => '$'.number_format($forecast['forecasts'][30]['velocity_based']),
                    'pipeline' => '$'.number_format($forecast['forecasts'][30]['pipeline_based']),
                    'blended' => '$'.number_format($forecast['forecasts'][30]['blended']),
                ],
                '60_day' => [
                    'velocity' => '$'.number_format($forecast['forecasts'][60]['velocity_based']),
                    'pipeline' => '$'.number_format($forecast['forecasts'][60]['pipeline_based']),
                    'blended' => '$'.number_format($forecast['forecasts'][60]['blended']),
                ],
                '90_day' => [
                    'velocity' => '$'.number_format($forecast['forecasts'][90]['velocity_based']),
                    'pipeline' => '$'.number_format($forecast['forecasts'][90]['pipeline_based']),
                    'blended' => '$'.number_format($forecast['forecasts'][90]['blended']),
                ],
            ],
            'year_end' => [
                'days_remaining' => $forecast['year_end']['days_remaining'],
                'velocity_forecast' => '$'.number_format($forecast['year_end']['velocity_forecast']),
                'pipeline_forecast' => '$'.number_format($forecast['year_end']['pipeline_forecast']),
                'will_hit_target' => $forecast['year_end']['will_hit_target'],
                'gap' => $forecast['year_end']['gap'] > 0
                    ? '-$'.number_format($forecast['year_end']['gap']).' short'
                    : '+$'.number_format(abs($forecast['year_end']['gap'])).' surplus',
            ],
            'analysis' => $this->analyzeForecast($goal, $forecast),
        ];
    }

    protected function analyzeForecast(StrategicGoal $goal, array $forecast): array
    {
        $analysis = [];

        // Target likelihood
        if ($forecast['year_end']['will_hit_target']) {
            $analysis[] = 'On track to hit annual target based on current trajectory.';
        } else {
            $gap = $forecast['year_end']['gap'];
            $daysLeft = $forecast['year_end']['days_remaining'];
            $requiredDaily = $daysLeft > 0 ? $gap / $daysLeft : $gap;

            $analysis[] = "Need additional \${$requiredDaily}/day (total \${$gap}) to hit target.";
        }

        // Pipeline health
        $pipelineCoverage = $forecast['weighted_pipeline'] / max(1, $goal->revenue_target - $forecast['current_revenue']);
        if ($pipelineCoverage < 1) {
            $analysis[] = 'Pipeline coverage is insufficient. Need more qualified opportunities.';
        } elseif ($pipelineCoverage < 2) {
            $analysis[] = 'Pipeline coverage is adequate but not strong. Continue lead generation.';
        } else {
            $analysis[] = 'Strong pipeline coverage. Focus on closing.';
        }

        // Velocity trend
        $expectedDaily = $goal->revenue_target / 365;
        if ($forecast['daily_velocity'] >= $expectedDaily) {
            $analysis[] = 'Revenue velocity is at or above required pace.';
        } else {
            $diff = round(($expectedDaily - $forecast['daily_velocity']) / $expectedDaily * 100);
            $analysis[] = "Revenue velocity is {$diff}% below required pace.";
        }

        return $analysis;
    }
}
