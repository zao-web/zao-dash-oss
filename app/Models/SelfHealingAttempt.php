<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfHealingAttempt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'source_line' => 'integer',
        'occurrence_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_SUCCESS = 'success';

    const STATUS_FAILED = 'failed';

    const STATUS_ESCALATED = 'escalated';

    const STATUS_SKIPPED = 'skipped';

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeEscalated($query)
    {
        return $query->where('status', self::STATUS_ESCALATED);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>', now()->subHours($hours));
    }

    public function markInProgress(): self
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        return $this;
    }

    public function markSuccess(string $commitSha, string $commitUrl, ?string $fixDescription = null, ?string $agentOutput = null): self
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'commit_sha' => $commitSha,
            'commit_url' => $commitUrl,
            'fix_description' => $fixDescription,
            'agent_output' => $agentOutput,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markFailed(string $reason, ?string $agentOutput = null): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
            'agent_output' => $agentOutput,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markEscalated(string $reason, ?string $agentOutput = null): self
    {
        $this->update([
            'status' => self::STATUS_ESCALATED,
            'failure_reason' => $reason,
            'agent_output' => $agentOutput,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markSkipped(string $reason): self
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function getDurationAttribute(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return $this->started_at->diffInSeconds($end);
    }

    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->duration;
        if ($seconds === null) {
            return '-';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes}m {$remainingSeconds}s";
    }

    public function getCommitShortAttribute(): ?string
    {
        return $this->commit_sha ? substr($this->commit_sha, 0, 7) : null;
    }

    public function getSlackPermalinkAttribute(): string
    {
        // Construct Slack permalink (requires workspace info)
        return "https://slack.com/archives/{$this->slack_channel_id}/p".
            str_replace('.', '', $this->slack_message_ts);
    }
}
