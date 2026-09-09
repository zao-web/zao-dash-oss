<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentActivityLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'metadata' => 'array',
    ];

    // Action constants
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_TRIGGERED = 'triggered';

    public const ACTION_CLONED = 'cloned';

    public const ACTION_CLONED_FROM = 'cloned_from';

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an agent activity.
     */
    public static function log(
        Agent $agent,
        string $action,
        ?array $changes = null,
        ?array $metadata = null,
    ): self {
        return self::create([
            'agent_id' => $agent->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'changes' => $changes,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log creation of an agent.
     */
    public static function logCreated(Agent $agent, ?array $metadata = null): self
    {
        return self::log($agent, self::ACTION_CREATED, null, $metadata);
    }

    /**
     * Log update of an agent with diff.
     */
    public static function logUpdated(Agent $agent, array $original, array $changed): self
    {
        $changes = [];
        foreach ($changed as $key => $value) {
            if (($original[$key] ?? null) !== $value) {
                $changes[$key] = [
                    'from' => $original[$key] ?? null,
                    'to' => $value,
                ];
            }
        }

        return self::log($agent, self::ACTION_UPDATED, $changes);
    }

    /**
     * Log status change.
     */
    public static function logStatusChanged(Agent $agent, string $from, string $to): self
    {
        return self::log($agent, self::ACTION_STATUS_CHANGED, [
            'status' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Log agent trigger.
     */
    public static function logTriggered(Agent $agent, string $source, ?int $runId = null): self
    {
        return self::log($agent, self::ACTION_TRIGGERED, null, [
            'source' => $source,
            'run_id' => $runId,
        ]);
    }

    /**
     * Log agent cloning.
     */
    public static function logCloned(Agent $original, Agent $clone): void
    {
        // Log on original that it was cloned
        self::log($original, self::ACTION_CLONED, null, [
            'clone_id' => $clone->id,
            'clone_slug' => $clone->slug,
        ]);

        // Log on clone that it was cloned from
        self::log($clone, self::ACTION_CLONED_FROM, null, [
            'original_id' => $original->id,
            'original_slug' => $original->slug,
        ]);
    }
}
