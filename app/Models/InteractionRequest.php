<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InteractionRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Question type constants.
     */
    public const TYPE_TEXT = 'text';

    public const TYPE_SELECT = 'select';

    public const TYPE_CONFIRM = 'confirm';

    /**
     * Response channel constants.
     */
    public const VIA_DASHBOARD = 'dashboard';

    public const VIA_SLACK = 'slack';

    public const VIA_MCP = 'mcp';

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'context' => 'array',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The agent run this interaction belongs to.
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * The user who responded to this interaction.
     */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_id');
    }

    /**
     * Scope to get only pending (unanswered) interactions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('responded_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to get expired interactions.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('responded_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope to get interactions awaiting a response.
     */
    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->whereNull('responded_at');
    }

    /**
     * Scope to get interactions for a specific agent run.
     */
    public function scopeForRun(Builder $query, int $runId): Builder
    {
        return $query->where('agent_run_id', $runId);
    }

    /**
     * Check if this interaction has been responded to.
     */
    public function isResponded(): bool
    {
        return $this->responded_at !== null;
    }

    /**
     * Check if this interaction has expired.
     */
    public function isExpired(): bool
    {
        return ! $this->isResponded() && $this->expires_at->isPast();
    }

    /**
     * Check if this interaction is still pending.
     */
    public function isPending(): bool
    {
        return ! $this->isResponded() && ! $this->isExpired();
    }

    /**
     * Get the remaining time until expiration.
     */
    public function getRemainingTimeAttribute(): ?int
    {
        if ($this->isResponded() || $this->isExpired()) {
            return null;
        }

        return now()->diffInSeconds($this->expires_at, false);
    }
}
