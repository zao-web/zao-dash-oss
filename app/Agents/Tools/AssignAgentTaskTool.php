<?php

namespace App\Agents\Tools;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\WeeklyPlanItem;
use Carbon\Carbon;

/**
 * Assign a task to another agent.
 *
 * The Business Strategist uses this to delegate work
 * to specialized agents like Lead Gen or Outreach.
 */
class AssignAgentTaskTool extends BaseTool
{
    public function category(): string
    {
        return 'agent';
    }

    public function name(): string
    {
        return 'Assign Agent Task';
    }

    public function description(): string
    {
        return 'Assign a task to another agent for execution. The strategist uses this to delegate work to specialized agents.';
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
                'agent_slug' => [
                    'type' => 'string',
                    'description' => 'Slug of agent to assign task to (e.g., lead-generation, outreach-campaign)',
                ],
                'task_description' => [
                    'type' => 'string',
                    'description' => 'Clear description of what the agent should do',
                ],
                'context' => [
                    'type' => 'object',
                    'description' => 'Additional context for the agent (targets, constraints, etc.)',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'normal', 'high', 'urgent'],
                    'description' => 'Task priority (default: normal)',
                ],
                'scheduled_for' => [
                    'type' => 'string',
                    'description' => 'ISO datetime to run task. Defaults to ASAP.',
                ],
                'weekly_plan_item_id' => [
                    'type' => 'integer',
                    'description' => 'Link to weekly plan item if applicable',
                ],
            ],
            'required' => ['agent_slug', 'task_description'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'agent_slug' => 'required|string',
            'task_description' => 'required|string|max:2000',
            'context' => 'nullable|array',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'scheduled_for' => 'nullable|date',
            'weekly_plan_item_id' => 'nullable|integer|exists:weekly_plan_items,id',
        ];
    }

    public function execute(array $params): array
    {
        // Find the agent
        $agent = Agent::where('slug', $params['agent_slug'])->first();

        if (! $agent) {
            return [
                'success' => false,
                'error' => 'agent_not_found',
                'message' => "Agent '{$params['agent_slug']}' not found.",
                'available_agents' => Agent::where('status', 'active')
                    ->pluck('slug')
                    ->toArray(),
            ];
        }

        // Check agent is active
        if ($agent->status !== 'active') {
            return [
                'success' => false,
                'error' => 'agent_not_active',
                'message' => "Agent '{$agent->name}' is not active (status: {$agent->status}).",
            ];
        }

        // Parse scheduled time
        $scheduledFor = ! empty($params['scheduled_for'])
            ? Carbon::parse($params['scheduled_for'])
            : null;

        // Create the task
        $task = AgentTask::create([
            'agent_id' => $agent->id,
            'weekly_plan_item_id' => $params['weekly_plan_item_id'] ?? null,
            'task_description' => $params['task_description'],
            'context' => $params['context'] ?? [],
            'priority' => $params['priority'] ?? 'normal',
            'status' => $scheduledFor ? 'scheduled' : 'pending',
            'scheduled_for' => $scheduledFor,
        ]);

        // Update linked plan item if exists
        if ($task->weekly_plan_item_id) {
            $planItem = WeeklyPlanItem::find($task->weekly_plan_item_id);
            $planItem?->update(['status' => 'in_progress']);
        }

        return [
            'success' => true,
            'task' => [
                'id' => $task->id,
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                ],
                'description' => $task->task_description,
                'priority' => $task->priority,
                'status' => $task->status,
                'scheduled_for' => $scheduledFor?->toDateTimeString() ?? 'ASAP (pending approval)',
            ],
            'note' => $agent->requires_approval
                ? 'Task requires approval before agent can execute.'
                : 'Task will execute when agent next runs.',
            'agent_schedule' => $this->getAgentScheduleInfo($agent),
        ];
    }

    protected function getAgentScheduleInfo(Agent $agent): ?array
    {
        $definition = $agent->getDefinition();
        if (! $definition) {
            return null;
        }

        $meta = $definition->metadata();

        return [
            'trigger' => $meta['trigger'] ?? 'manual',
            'schedule' => $meta['schedule'] ?? null,
            'requires_approval' => $meta['requires_approval'] ?? true,
        ];
    }
}
