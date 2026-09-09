<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentRun extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'output' => 'array',
        'trigger_metadata' => 'array',
        'checkpoint' => 'array',
        'cost_usd' => 'decimal:4',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['duration_ms'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskActivities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    /**
     * Calculate duration in milliseconds from started_at and completed_at.
     */
    public function getDurationMsAttribute(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->completed_at);
    }

    /**
     * Alias for error_message column for backward compatibility.
     */
    public function getErrorAttribute(): ?string
    {
        return $this->error_message;
    }

    /**
     * Status constants.
     */
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_AWAITING_INPUT = 'awaiting_input';

    /**
     * Invocation source constants.
     */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_COMMAND_PALETTE = 'command_palette';

    public const SOURCE_API = 'api';

    public const SOURCE_CHAINED = 'chained';

    public const SOURCE_CHAIN = 'chain';

    public const SOURCE_TASK = 'task';

    public const SOURCE_SLACK = 'slack';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approvalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class);
    }

    public function interactionRequests(): HasMany
    {
        return $this->hasMany(InteractionRequest::class);
    }

    /**
     * Get the current pending interaction request, if any.
     */
    public function pendingInteraction(): HasOne
    {
        return $this->hasOne(InteractionRequest::class)
            ->whereNull('responded_at')
            ->where('expires_at', '>', now())
            ->latest();
    }

    public function chainRun(): BelongsTo
    {
        return $this->belongsTo(AgentChainRun::class, 'chain_run_id');
    }

    /**
     * Check if this run is part of a chain.
     */
    public function isPartOfChain(): bool
    {
        return $this->chain_run_id !== null;
    }

    /**
     * Scope to filter by invocation source.
     */
    public function scopeFromSource($query, string $source)
    {
        return $query->where('invocation_source', $source);
    }

    /**
     * Check if this run is from an automated source.
     */
    public function isAutomated(): bool
    {
        return in_array($this->invocation_source, [
            self::SOURCE_WEBHOOK,
            self::SOURCE_SCHEDULED,
            self::SOURCE_CHAINED,
        ]);
    }

    /**
     * Check if this run is awaiting user input.
     */
    public function isAwaitingInput(): bool
    {
        return $this->status === self::STATUS_AWAITING_INPUT;
    }

    /**
     * Check if this run can be resumed with a response.
     */
    public function canResume(): bool
    {
        return $this->isAwaitingInput() && $this->checkpoint !== null;
    }
}
