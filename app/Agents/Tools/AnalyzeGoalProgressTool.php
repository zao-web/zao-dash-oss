<?php

namespace App\Agents\Tools;

use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;

/**
 * Analyze progress toward a strategic goal.
 *
 * Returns revenue, leads, deals, and pipeline actuals vs targets,
 * plus pace tracking and variance calculations.
 */
class AnalyzeGoalProgressTool extends BaseTool
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
        return 'Analyze Goal Progress';
    }

    public function description(): string
    {
        return 'Analyze current progress toward a strategic revenue goal. Returns actuals vs targets for revenue, leads, deals, and pipeline with pace tracking.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'goal_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the strategic goal (defaults to active goal)',
                ],
                'include_levers' => [
                    'type' => 'boolean',
                    'description' => 'Include recommended actions if behind target',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'goal_id' => 'nullable|integer|exists:strategic_goals,id',
            'include_levers' => 'nullable|boolean',
        ];
    }

    public function execute(array $params): array
    {
        // Get goal - defaults to active goal
        if (! empty($params['goal_id'])) {
            $goal = StrategicGoal::find($params['goal_id']);
        } else {
            $goal = StrategicGoal::where('status', 'active')->latest()->first();
        }

        if (! $goal) {
            return [
                'success' => false,
                'error' => 'no_active_goal',
                'message' => 'No strategic goal found. Create a goal first.',
            ];
        }

        $progress = $this->intelligence->calculateProgress($goal);
        $requirements = $this->intelligence->calculateRequiredLeadsPerWeek($goal);

        $result = [
            'success' => true,
            'goal' => [
                'id' => $goal->id,
                'fiscal_year' => $goal->fiscal_year,
                'revenue_target' => $goal->revenue_target,
                'margin_target_pct' => $goal->margin_target_pct,
            ],
            'progress' => $progress,
            'requirements' => [
                'leads_per_week' => $requirements['leads_per_week'],
                'deals_needed' => $requirements['deals_needed'],
                'weeks_remaining' => $requirements['weeks_remaining'],
            ],
            'summary' => $this->buildSummary($progress, $requirements),
        ];

        // Include levers if requested and behind pace
        if (! empty($params['include_levers']) && ! $progress['pace']['is_on_track']) {
            $result['levers'] = $this->intelligence->identifyLevers($goal);
        }

        return $result;
    }

    protected function buildSummary(array $progress, array $requirements): string
    {
        $status = $progress['pace']['status'];
        $revenueProgress = round($progress['revenue']['progress_pct'], 1);
        $timeElapsed = round($progress['pace']['time_elapsed_pct'], 1);

        $summary = "Revenue: {$revenueProgress}% of target ({$timeElapsed}% of year elapsed). ";
        $summary .= "Status: {$status}. ";
        $summary .= "Need {$requirements['leads_per_week']} leads/week to hit target in {$requirements['weeks_remaining']} weeks.";

        return $summary;
    }
}
