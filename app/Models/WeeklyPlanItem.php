<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expected_outcome' => 'array',
        'result' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const OWNER_HUMAN = 'human';

    public const OWNER_AGENT = 'agent';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public function weeklyPlan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * Get the agent model if this is an agent task.
     */
    public function getAgentAttribute(): ?Agent
    {
        if ($this->owner_type !== self::OWNER_AGENT || ! $this->agent_slug) {
            return null;
        }

        return Agent::where('slug', $this->agent_slug)->first();
    }

    /**
     * Is this a human task?
     */
    public function getIsHumanTaskAttribute(): bool
    {
        return $this->owner_type === self::OWNER_HUMAN;
    }

    /**
     * Is this an agent task?
     */
    public function getIsAgentTaskAttribute(): bool
    {
        return $this->owner_type === self::OWNER_AGENT;
    }

    /**
     * Due date based on due_day and week.
     */
    public function getDueDateAttribute(): ?\Carbon\Carbon
    {
        if (! $this->due_day) {
            return null;
        }

        $dayMap = [
            'monday' => 0,
            'tuesday' => 1,
            'wednesday' => 2,
            'thursday' => 3,
            'friday' => 4,
            'saturday' => 5,
            'sunday' => 6,
        ];

        $offset = $dayMap[strtolower($this->due_day)] ?? 0;

        return $this->weeklyPlan->week_starting->copy()->addDays($offset);
    }

    /**
     * Is this item overdue?
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_SKIPPED) {
            return false;
        }

        $dueDate = $this->due_date;

        return $dueDate && now()->gt($dueDate->endOfDay());
    }

    /**
     * Start working on this item.
     */
    public function start(): void
    {
        $this->update(['status' => self::STATUS_IN_PROGRESS]);
    }

    /**
     * Complete this item with result.
     */
    public function complete(array $result = [], ?int $agentRunId = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'agent_run_id' => $agentRunId,
        ]);
    }

    /**
     * Skip this item with reason.
     */
    public function skip(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'result' => ['skipped_reason' => $reason],
        ]);
    }

    /**
     * Create an AgentTask from this item.
     */
    public function createAgentTask(array $context = []): ?AgentTask
    {
        if (! $this->is_agent_task) {
            return null;
        }

        $agent = $this->agent;
        if (! $agent) {
            return null;
        }

        return AgentTask::create([
            'agent_id' => $agent->id,
            'weekly_plan_item_id' => $this->id,
            'task_description' => $this->action,
            'context' => array_merge([
                'success_metric' => $this->success_metric,
                'expected_outcome' => $this->expected_outcome,
                'priority' => $this->priority,
            ], $context),
            'priority' => $this->mapPriority(),
            'status' => 'pending',
        ]);
    }

    /**
     * Map item priority to task priority.
     */
    protected function mapPriority(): string
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL => 'urgent',
            self::PRIORITY_HIGH => 'high',
            self::PRIORITY_LOW => 'low',
            default => 'normal',
        };
    }
}
