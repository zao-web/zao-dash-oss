<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAgentTasksJob;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\StrategicGoal;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanItem;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WeeklyPlanController extends Controller
{
    public function index()
    {
        $plans = WeeklyPlan::with(['items', 'strategicGoal', 'createdBy', 'approvedBy'])
            ->orderByDesc('week_starting')
            ->limit(12)
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'week_starting' => $plan->week_starting->toDateString(),
                'week_label' => $plan->week_label,
                'is_current_week' => $plan->is_current_week,
                'status' => $plan->status,
                'focus_areas' => $plan->focus_areas ?? [],
                'targets' => $plan->targets ?? [],
                'progress_percent' => $plan->progress_percent,
                'items_count' => $plan->items->count(),
                'completed_count' => $plan->items->where('status', 'completed')->count(),
                'agent_items_count' => $plan->items->where('owner_type', 'agent')->count(),
                'human_items_count' => $plan->items->where('owner_type', 'human')->count(),
                'goal_name' => $plan->strategicGoal?->name,
            ]);

        $currentPlan = WeeklyPlan::current();
        $activeGoal = StrategicGoal::where('status', 'active')->first();

        return Inertia::render('WeeklyPlan/Index', [
            'plans' => $plans,
            'currentPlan' => $currentPlan ? [
                'id' => $currentPlan->id,
                'week_label' => $currentPlan->week_label,
                'status' => $currentPlan->status,
                'focus_areas' => $currentPlan->focus_areas ?? [],
                'targets' => $currentPlan->targets ?? [],
                'progress_percent' => $currentPlan->progress_percent,
                'strategy_notes' => $currentPlan->strategy_notes,
                'items' => $currentPlan->items->map(fn ($item) => [
                    'id' => $item->id,
                    'action' => $item->action,
                    'owner_type' => $item->owner_type,
                    'agent_slug' => $item->agent_slug,
                    'priority' => $item->priority,
                    'due_day' => $item->due_day,
                    'status' => $item->status,
                    'is_overdue' => $item->is_overdue,
                ]),
            ] : null,
            'activeGoal' => $activeGoal ? [
                'id' => $activeGoal->id,
                'name' => $activeGoal->name,
                'progress_percent' => $activeGoal->progress_percent,
            ] : null,
            'availableAgents' => Agent::where('status', 'active')
                ->whereNotNull('schedule')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(WeeklyPlan $weeklyPlan)
    {
        $weeklyPlan->load(['items', 'strategicGoal', 'createdBy', 'approvedBy', 'rejectedBy']);

        $agentTasks = AgentTask::whereIn('weekly_plan_item_id', $weeklyPlan->items->pluck('id'))
            ->with(['agent', 'agentRun'])
            ->get()
            ->keyBy('weekly_plan_item_id');

        return Inertia::render('WeeklyPlan/Show', [
            'plan' => [
                'id' => $weeklyPlan->id,
                'week_starting' => $weeklyPlan->week_starting->toDateString(),
                'week_ending' => $weeklyPlan->week_ending->toDateString(),
                'week_label' => $weeklyPlan->week_label,
                'is_current_week' => $weeklyPlan->is_current_week,
                'status' => $weeklyPlan->status,
                'focus_areas' => $weeklyPlan->focus_areas ?? [],
                'targets' => $weeklyPlan->targets ?? [],
                'strategy_notes' => $weeklyPlan->strategy_notes,
                'week_results' => $weeklyPlan->week_results,
                'progress_percent' => $weeklyPlan->progress_percent,
                'approved_at' => $weeklyPlan->approved_at?->toDateTimeString(),
                'approved_by' => $weeklyPlan->approvedBy?->name,
                'created_by' => $weeklyPlan->createdBy?->name,
                'goal' => $weeklyPlan->strategicGoal ? [
                    'id' => $weeklyPlan->strategicGoal->id,
                    'name' => $weeklyPlan->strategicGoal->name,
                    'revenue_target' => $weeklyPlan->strategicGoal->revenue_target,
                ] : null,
                // Rejection/Revision fields
                'rejected_by' => $weeklyPlan->rejectedBy?->name,
                'rejected_at' => $weeklyPlan->rejected_at?->toDateTimeString(),
                'rejection_reason' => $weeklyPlan->rejection_reason,
                'revision_history' => $weeklyPlan->revision_history ? collect($weeklyPlan->revision_history)->map(function ($entry) {
                    return [
                        'feedback' => $entry['feedback'] ?? '',
                        'requested_by' => isset($entry['requested_by']) ? User::find($entry['requested_by'])?->name : 'Unknown',
                        'requested_at' => $entry['requested_at'] ?? '',
                    ];
                })->toArray() : null,
                'revision_feedback' => $weeklyPlan->revision_feedback,
            ],
            'items' => $weeklyPlan->items->map(fn ($item) => [
                'id' => $item->id,
                'action' => $item->action,
                'owner_type' => $item->owner_type,
                'agent_slug' => $item->agent_slug,
                'priority' => $item->priority,
                'due_day' => $item->due_day,
                'due_date' => $item->due_date?->toDateString(),
                'success_metric' => $item->success_metric,
                'expected_outcome' => $item->expected_outcome,
                'status' => $item->status,
                'result' => $item->result,
                'is_overdue' => $item->is_overdue,
                'agent_task' => $agentTasks->get($item->id) ? [
                    'id' => $agentTasks->get($item->id)->id,
                    'status' => $agentTasks->get($item->id)->status,
                    'scheduled_for' => $agentTasks->get($item->id)->scheduled_for?->toDateTimeString(),
                    'completed_at' => $agentTasks->get($item->id)->completed_at?->toDateTimeString(),
                ] : null,
            ]),
            'stats' => [
                'total' => $weeklyPlan->items->count(),
                'completed' => $weeklyPlan->items->where('status', 'completed')->count(),
                'in_progress' => $weeklyPlan->items->where('status', 'in_progress')->count(),
                'pending' => $weeklyPlan->items->where('status', 'pending')->count(),
                'skipped' => $weeklyPlan->items->where('status', 'skipped')->count(),
                'agent_items' => $weeklyPlan->items->where('owner_type', 'agent')->count(),
                'human_items' => $weeklyPlan->items->where('owner_type', 'human')->count(),
            ],
        ]);
    }

    public function approve(WeeklyPlan $weeklyPlan, Request $request)
    {
        if ($weeklyPlan->status !== 'draft') {
            return back()->with('error', 'Only draft plans can be approved.');
        }

        $weeklyPlan->approve($request->user());

        // Create agent tasks for agent items
        foreach ($weeklyPlan->items()->where('owner_type', 'agent')->get() as $item) {
            $item->createAgentTask([
                'approved_by' => $request->user()->id,
                'approved_at' => now()->toIso8601String(),
            ]);
        }

        return back()->with('success', 'Plan approved. Agent tasks have been scheduled.');
    }

    public function activate(WeeklyPlan $weeklyPlan)
    {
        try {
            $weeklyPlan->activate();

            return back()->with('success', 'Plan activated.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateItem(WeeklyPlanItem $item, Request $request)
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:pending,in_progress,completed,skipped',
            'result' => 'sometimes|array',
        ]);

        if (isset($validated['status'])) {
            match ($validated['status']) {
                'in_progress' => $item->start(),
                'completed' => $item->complete($validated['result'] ?? []),
                'skipped' => $item->skip($request->input('skip_reason')),
                default => $item->update(['status' => $validated['status']]),
            };
        }

        return back()->with('success', 'Item updated.');
    }

    /**
     * Reject a weekly plan.
     */
    public function reject(WeeklyPlan $weeklyPlan, Request $request)
    {
        if (! in_array($weeklyPlan->status, ['draft', 'revision_requested'])) {
            return back()->with('error', 'Only draft or revision-requested plans can be rejected.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $weeklyPlan->reject($request->user(), $validated['reason']);

        return back()->with('success', 'Plan rejected.');
    }

    /**
     * Request revision of a weekly plan with feedback.
     */
    public function requestRevision(WeeklyPlan $weeklyPlan, Request $request)
    {
        if (! $weeklyPlan->canBeRevised()) {
            return back()->with('error', 'This plan cannot be revised in its current state.');
        }

        $validated = $request->validate([
            'feedback' => 'required|string|max:2000',
        ]);

        $weeklyPlan->requestRevision($request->user(), $validated['feedback']);

        // Trigger Business Strategist to revise the plan
        $agent = Agent::where('slug', 'business-strategist')->first();

        if ($agent && $agent->status === 'active') {
            // Create a revision task
            $task = AgentTask::create([
                'agent_id' => $agent->id,
                'description' => 'Revise weekly plan based on feedback',
                'status' => 'pending',
                'priority' => 'high',
                'context' => [
                    'action' => 'revise_plan',
                    'plan_id' => $weeklyPlan->id,
                    'feedback' => $validated['feedback'],
                    'previous_focus_areas' => $weeklyPlan->focus_areas,
                    'previous_targets' => $weeklyPlan->targets,
                    'revision_count' => $weeklyPlan->revision_count,
                ],
                'scheduled_for' => now(),
            ]);

            // Dispatch job to process the task immediately (queued)
            ProcessAgentTasksJob::dispatch();

            return back()->with('success', 'Revision requested. Business Strategist will create an updated plan based on your feedback.');
        }

        return back()->with('warning', 'Revision requested but Business Strategist agent is not active. Please revise manually or activate the agent.');
    }
}
