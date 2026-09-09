<?php

namespace App\Agents\Tools;

use App\Models\StrategicGoal;
use App\Models\WeeklyPlan;
use Carbon\Carbon;

/**
 * Create a weekly action plan.
 *
 * Creates structured plans with focus areas, targets, and action items.
 * Requires approval before activation.
 */
class CreateWeeklyPlanTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Weekly Plan';
    }

    public function description(): string
    {
        return 'Create a structured weekly action plan with focus areas, targets, and action items for both humans and agents. Requires approval.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week_starting' => [
                    'type' => 'string',
                    'description' => 'Monday of the week (YYYY-MM-DD). Defaults to next Monday.',
                ],
                'focus_areas' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Focus areas for the week (e.g., lead_gen, closing, upsells)',
                ],
                'targets' => [
                    'type' => 'object',
                    'description' => 'Weekly targets (e.g., {leads: 10, revenue: 50000})',
                ],
                'strategy_notes' => [
                    'type' => 'string',
                    'description' => 'Strategic context and rationale for the plan',
                ],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string'],
                            'owner_type' => ['type' => 'string', 'enum' => ['human', 'agent']],
                            'agent_slug' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            'due_day' => ['type' => 'string'],
                            'success_metric' => ['type' => 'string'],
                        ],
                        'required' => ['action', 'owner_type'],
                    ],
                    'description' => 'Action items for the week',
                ],
                'goal_id' => [
                    'type' => 'integer',
                    'description' => 'Link to strategic goal',
                ],
            ],
            'required' => ['focus_areas', 'items'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'week_starting' => 'nullable|date',
            'focus_areas' => 'required|array|min:1',
            'focus_areas.*' => 'string',
            'targets' => 'nullable|array',
            'strategy_notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.action' => 'required|string|max:500',
            'items.*.owner_type' => 'required|in:human,agent',
            'items.*.agent_slug' => 'nullable|string',
            'items.*.priority' => 'nullable|in:low,medium,high,critical',
            'items.*.due_day' => 'nullable|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'items.*.success_metric' => 'nullable|string|max:255',
            'goal_id' => 'nullable|integer|exists:strategic_goals,id',
        ];
    }

    public function execute(array $params): array
    {
        // Determine week starting date (must be Monday)
        if (! empty($params['week_starting'])) {
            $weekStarting = Carbon::parse($params['week_starting'])->startOfWeek(Carbon::MONDAY);
        } else {
            $weekStarting = now()->next(Carbon::MONDAY);
            if (now()->isMonday() && now()->hour < 12) {
                $weekStarting = now()->startOfWeek(Carbon::MONDAY);
            }
        }

        // Check for existing plan
        $existingPlan = WeeklyPlan::where('week_starting', $weekStarting->toDateString())->first();
        if ($existingPlan && $existingPlan->status !== 'draft') {
            return [
                'success' => false,
                'error' => 'plan_exists',
                'message' => "A plan already exists for week of {$weekStarting->format('M j')} with status: {$existingPlan->status}",
                'existing_plan_id' => $existingPlan->id,
            ];
        }

        // Get goal if provided
        $goalId = $params['goal_id'] ?? null;
        if (! $goalId) {
            $activeGoal = StrategicGoal::where('status', 'active')->latest()->first();
            $goalId = $activeGoal?->id;
        }

        // Create or update plan
        $plan = WeeklyPlan::updateOrCreate(
            ['week_starting' => $weekStarting->toDateString()],
            [
                'strategic_goal_id' => $goalId,
                'focus_areas' => $params['focus_areas'],
                'targets' => $params['targets'] ?? [],
                'strategy_notes' => $params['strategy_notes'] ?? null,
                'status' => 'draft',
            ]
        );

        // Remove existing items if updating
        if ($existingPlan) {
            $plan->items()->delete();
        }

        // Create items
        $createdItems = [];
        foreach ($params['items'] as $itemData) {
            $item = $plan->items()->create([
                'action' => $itemData['action'],
                'owner_type' => $itemData['owner_type'],
                'agent_slug' => $itemData['agent_slug'] ?? null,
                'priority' => $itemData['priority'] ?? 'medium',
                'due_day' => $itemData['due_day'] ?? null,
                'success_metric' => $itemData['success_metric'] ?? null,
                'expected_outcome' => $itemData['expected_outcome'] ?? null,
                'status' => 'pending',
            ]);

            $createdItems[] = [
                'id' => $item->id,
                'action' => $item->action,
                'owner' => $item->owner_type === 'agent' ? "Agent: {$item->agent_slug}" : 'Human',
                'priority' => $item->priority,
                'due' => $item->due_day,
            ];
        }

        return [
            'success' => true,
            'plan' => [
                'id' => $plan->id,
                'week' => $plan->week_label,
                'status' => $plan->status,
                'focus_areas' => $plan->focus_areas,
                'targets' => $plan->targets,
            ],
            'items_created' => count($createdItems),
            'items' => $createdItems,
            'note' => 'Plan created as draft. Requires approval before activation.',
            'next_steps' => [
                'Review the plan and items',
                'Approve to activate',
                'Agent tasks will be scheduled upon activation',
            ],
        ];
    }
}
