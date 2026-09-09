<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskActivity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'hours_logged' => 'decimal:2',
        'hours_estimated' => 'decimal:2',
    ];

    public const TYPE_AGENT_ASSIGNED = 'agent_assigned';

    public const TYPE_AGENT_STARTED = 'agent_started';

    public const TYPE_AGENT_COMPLETED = 'agent_completed';

    public const TYPE_AGENT_FAILED = 'agent_failed';

    public const TYPE_PR_CREATED = 'pr_created';

    public const TYPE_PR_MERGED = 'pr_merged';

    public const TYPE_DEPLOYED = 'deployed';

    public const TYPE_TIME_LOGGED = 'time_logged';

    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_COMMENT_ADDED = 'comment_added';

    public const TYPE_CREDENTIALS_LOADED = 'credentials_loaded';

    public const TYPE_PREVIEW_CREATED = 'preview_created';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public static function logAgentAssigned(Task $task, Agent $agent, ?User $user = null): self
    {
        return static::create([
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'user_id' => $user?->id,
            'type' => self::TYPE_AGENT_ASSIGNED,
            'description' => "Agent '{$agent->name}' assigned to task",
            'metadata' => [
                'agent_slug' => $agent->slug,
                'agent_model' => $agent->model,
            ],
        ]);
    }

    public static function logAgentStarted(Task $task, AgentRun $run): self
    {
        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run->agent_id,
            'agent_run_id' => $run->id,
            'type' => self::TYPE_AGENT_STARTED,
            'description' => "Agent execution started (Run #{$run->id})",
            'metadata' => [
                'session_id' => $run->session_id,
            ],
        ]);
    }

    public static function logAgentCompleted(
        Task $task,
        AgentRun $run,
        ?float $humanHours = null,
        ?float $agentSeconds = null
    ): self {
        $description = 'Agent completed successfully';
        if ($humanHours) {
            $description .= " ({$humanHours}h estimated human time)";
        }

        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run->agent_id,
            'agent_run_id' => $run->id,
            'type' => self::TYPE_AGENT_COMPLETED,
            'description' => $description,
            'hours_estimated' => $humanHours,
            'metadata' => [
                'session_id' => $run->session_id,
                'cost_usd' => $run->cost_usd,
                'agent_seconds' => $agentSeconds,
                'duration_ms' => $run->duration_ms,
            ],
        ]);
    }

    public static function logAgentFailed(Task $task, AgentRun $run, string $error): self
    {
        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run->agent_id,
            'agent_run_id' => $run->id,
            'type' => self::TYPE_AGENT_FAILED,
            'description' => 'Agent execution failed: '.\Illuminate\Support\Str::limit($error, 100),
            'metadata' => [
                'session_id' => $run->session_id,
                'error' => $error,
            ],
        ]);
    }

    public static function logPrCreated(Task $task, string $prUrl, string $prNumber, ?AgentRun $run = null): self
    {
        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run?->agent_id,
            'agent_run_id' => $run?->id,
            'type' => self::TYPE_PR_CREATED,
            'description' => "Pull request #{$prNumber} created",
            'pr_url' => $prUrl,
            'pr_number' => $prNumber,
        ]);
    }

    public static function logPrMerged(Task $task, string $prUrl, string $prNumber): self
    {
        return static::create([
            'task_id' => $task->id,
            'type' => self::TYPE_PR_MERGED,
            'description' => "Pull request #{$prNumber} merged",
            'pr_url' => $prUrl,
            'pr_number' => $prNumber,
        ]);
    }

    public static function logTimeEntry(
        Task $task,
        float $hours,
        ?int $harvestEntryId = null,
        ?TimeEntry $timeEntry = null,
        ?AgentRun $run = null
    ): self {
        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run?->agent_id,
            'agent_run_id' => $run?->id,
            'type' => self::TYPE_TIME_LOGGED,
            'description' => "{$hours}h logged to Harvest",
            'hours_logged' => $hours,
            'time_entry_id' => $timeEntry?->id,
            'harvest_time_entry_id' => $harvestEntryId,
        ]);
    }

    public static function logStatusChange(Task $task, string $oldStatus, string $newStatus, ?User $user = null): self
    {
        return static::create([
            'task_id' => $task->id,
            'user_id' => $user?->id,
            'type' => self::TYPE_STATUS_CHANGED,
            'description' => "Status changed from '{$oldStatus}' to '{$newStatus}'",
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
    }

    public static function logCredentialsLoaded(Task $task, array $secretNames, ?AgentRun $run = null): self
    {
        $count = count($secretNames);

        return static::create([
            'task_id' => $task->id,
            'agent_id' => $run?->agent_id,
            'agent_run_id' => $run?->id,
            'type' => self::TYPE_CREDENTIALS_LOADED,
            'description' => "{$count} credential(s) loaded from Vault",
            'metadata' => [
                'secrets' => $secretNames,
            ],
        ]);
    }

    public static function logPreviewCreated(Task $task, ?string $previewUrl, string $branch): self
    {
        return static::create([
            'task_id' => $task->id,
            'type' => self::TYPE_PREVIEW_CREATED,
            'description' => "Preview environment created for branch {$branch}",
            'metadata' => [
                'preview_url' => $previewUrl,
                'branch' => $branch,
            ],
        ]);
    }

    public function scopeForTask($query, int $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
