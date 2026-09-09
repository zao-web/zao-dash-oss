<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'icon',
        'severity',
        'action_url',
        'action_label',
        'metadata',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeUndismissed($query)
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeForUser($query, ?int $userId = null)
    {
        if ($userId) {
            return $query->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereNull('user_id'); // Global notifications
            });
        }

        return $query->whereNull('user_id');
    }

    public function markAsRead(): self
    {
        $this->update(['read_at' => now()]);

        return $this;
    }

    public function dismiss(): self
    {
        $this->update(['dismissed_at' => now()]);

        return $this;
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    // Factory methods for common notification types
    public static function approvalNeeded(ApprovalRequest $approval): self
    {
        return self::create([
            'user_id' => null, // All users see approvals
            'type' => 'approval_needed',
            'title' => 'Approval Required',
            'message' => $approval->description,
            'icon' => '⚠️',
            'severity' => match ($approval->risk_level ?? 'medium') {
                'high' => 'error',
                'medium' => 'warning',
                default => 'info',
            },
            'action_url' => '/approvals',
            'action_label' => 'Review',
            'metadata' => [
                'approval_id' => $approval->id,
                'agent_name' => $approval->agentRun?->agent?->name,
                'action_type' => $approval->action_type,
            ],
        ]);
    }

    public static function agentCompleted(AgentRun $run): self
    {
        $success = $run->status === 'completed';

        return self::create([
            'user_id' => null,
            'type' => 'agent_run',
            'title' => $success ? 'Agent Completed' : 'Agent Failed',
            'message' => "{$run->agent->name} ".($success ? 'finished successfully' : 'encountered an error'),
            'icon' => $success ? '✅' : '❌',
            'severity' => $success ? 'success' : 'error',
            'action_url' => "/agents/{$run->agent->slug}/runs/{$run->id}",
            'action_label' => 'View Run',
            'metadata' => [
                'agent_id' => $run->agent_id,
                'agent_name' => $run->agent->name,
                'run_id' => $run->id,
                'status' => $run->status,
            ],
        ]);
    }

    public static function syncComplete(string $service, int $count, ?int $userId = null): self
    {
        return self::create([
            'user_id' => $userId,
            'type' => 'sync_complete',
            'title' => "{$service} Sync Complete",
            'message' => "Synced {$count} records from {$service}",
            'icon' => '🔄',
            'severity' => 'success',
            'action_url' => '/settings/integrations',
            'action_label' => 'View',
            'metadata' => [
                'service' => $service,
                'count' => $count,
            ],
        ]);
    }

    public static function system(string $title, string $message, string $severity = 'info'): self
    {
        return self::create([
            'user_id' => null,
            'type' => 'system',
            'title' => $title,
            'message' => $message,
            'icon' => match ($severity) {
                'success' => '✅',
                'warning' => '⚠️',
                'error' => '❌',
                default => 'ℹ️',
            },
            'severity' => $severity,
        ]);
    }

    /**
     * Notify about a Slack action item that needs attention.
     * Used when action items are detected but can't be fully processed
     * (e.g., client has no active project).
     */
    public static function slackActionItem(
        Client $client,
        string $actionItem,
        string $channel,
        ?string $permalink = null,
        ?string $suggestion = null
    ): self {
        // Build a create project URL with pre-filled data
        $createProjectUrl = "/clients/{$client->slug}?".http_build_query([
            'create_project' => '1',
            'project_name' => $channel, // Use channel name as suggested project name
        ]);

        return self::create([
            'user_id' => null,
            'type' => 'slack_action_item',
            'title' => "Action item from {$client->name}",
            'message' => $suggestion ?? "New action item detected in #{$channel}: {$actionItem}",
            'icon' => '💬',
            'severity' => 'warning',
            'action_url' => $permalink ?? "/clients/{$client->slug}",
            'action_label' => $permalink ? 'View in Slack' : 'View Client',
            'metadata' => [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'client_slug' => $client->slug,
                'channel' => $channel,
                'action_item' => $actionItem,
                'permalink' => $permalink,
                'suggestion' => $suggestion,
                'create_project_url' => $createProjectUrl,
            ],
        ]);
    }
}
