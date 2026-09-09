<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTask extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'result' => 'array',
        'scheduled_for' => 'datetime',
        'retry_due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RETRY_QUEUED = 'retry_queued';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function weeklyPlanItem(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanItem::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Scope: pending tasks ready to run.
     */
    public function scopeReady($query)
    {
        return $query->where(function ($query) {
            $query->where(function ($query) {
                $query->where('status', self::STATUS_PENDING)
                    ->where(function ($query) {
                        $query->whereNull('scheduled_for')
                            ->orWhere('scheduled_for', '<=', now());
                    });
            })->orWhere(function ($query) {
                $query->where('status', self::STATUS_RETRY_QUEUED)
                    ->whereNotNull('retry_due_at')
                    ->where('retry_due_at', '<=', now());
            });
        });
    }

    /**
     * Scope: scheduled for future.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_for', '>', now());
    }

    /**
     * Scope: by priority.
     */
    public function scopeByPriority($query)
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: use array_position
            return $query->orderByRaw("array_position(ARRAY['urgent', 'high', 'normal', 'low'], priority)");
        }

        // MySQL/SQLite: use FIELD or CASE
        return $query->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END");
    }

    /**
     * Is this task ready to execute?
     */
    public function getIsReadyAttribute(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        if ($this->scheduled_for && $this->scheduled_for->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Schedule for a specific time.
     */
    public function scheduleFor(\DateTimeInterface $dateTime): void
    {
        $this->update([
            'status' => self::STATUS_SCHEDULED,
            'scheduled_for' => $dateTime,
        ]);
    }

    /**
     * Mark as running.
     */
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'retry_due_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Mark as completed with result.
     */
    public function complete(array $result, ?int $agentRunId = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'agent_run_id' => $agentRunId,
            'completed_at' => now(),
            'retry_due_at' => null,
            'last_error' => null,
        ]);

        // Also update linked plan item if exists
        $this->weeklyPlanItem?->complete($result, $agentRunId);
    }

    /**
     * Mark as failed with error.
     */
    public function fail(string $error, ?int $agentRunId = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'result' => ['error' => $error],
            'agent_run_id' => $agentRunId,
            'completed_at' => now(),
            'last_error' => $error,
            'retry_due_at' => null,
        ]);
    }

    public function queueRetry(int $attempt, int $delayMilliseconds, ?string $error = null, ?int $agentRunId = null): void
    {
        $dueAt = now()->addMilliseconds(max($delayMilliseconds, 0));

        $this->update([
            'status' => self::STATUS_RETRY_QUEUED,
            'agent_run_id' => $agentRunId,
            'retry_attempt' => $attempt,
            'retry_due_at' => $dueAt,
            'last_error' => $error,
            'result' => $error ? ['error' => $error] : $this->result,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Get execution context for the agent.
     */
    public function getExecutionContext(): array
    {
        $context = $this->context ?? [];

        // Add plan context if linked
        if ($this->weeklyPlanItem) {
            $plan = $this->weeklyPlanItem->weeklyPlan;
            $context['weekly_plan'] = [
                'week_starting' => $plan->week_starting->toDateString(),
                'focus_areas' => $plan->focus_areas,
                'targets' => $plan->targets,
            ];

            if ($plan->strategicGoal) {
                $context['strategic_goal'] = [
                    'fiscal_year' => $plan->strategicGoal->fiscal_year,
                    'revenue_target' => $plan->strategicGoal->revenue_target,
                    'margin_target_pct' => $plan->strategicGoal->margin_target_pct,
                ];
            }
        }

        return $context;
    }

    /**
     * Create a task for an agent from the strategist.
     */
    public static function createFromStrategist(
        Agent $agent,
        string $description,
        array $context = [],
        string $priority = 'normal',
        ?\DateTimeInterface $scheduledFor = null
    ): self {
        return static::create([
            'agent_id' => $agent->id,
            'task_description' => $description,
            'context' => $context,
            'priority' => $priority,
            'status' => $scheduledFor ? self::STATUS_SCHEDULED : self::STATUS_PENDING,
            'scheduled_for' => $scheduledFor,
        ]);
    }
}
