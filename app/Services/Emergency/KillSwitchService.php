<?php

namespace App\Services\Emergency;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class KillSwitchService
{
    /**
     * Cache key for global kill switch.
     */
    const GLOBAL_KILL_KEY = 'killswitch:global';

    /**
     * Cache key for spend limit tracking.
     */
    const SPEND_KEY_PREFIX = 'spend:agent:';

    /**
     * Default daily spend limit per agent.
     */
    const DEFAULT_DAILY_LIMIT = 100.00;

    /**
     * Activate global kill switch - stops ALL agent activity.
     */
    public function activateGlobal(string $reason, ?int $userId = null): void
    {
        Cache::forever(self::GLOBAL_KILL_KEY, [
            'active' => true,
            'activated_at' => now()->toIso8601String(),
            'activated_by' => $userId,
            'reason' => $reason,
        ]);

        // Cancel all pending approvals
        ApprovalRequest::where('status', 'pending')
            ->update(['status' => 'cancelled', 'notes' => 'Cancelled by kill switch: '.$reason]);

        // Mark all running agent runs as cancelled
        AgentRun::where('status', 'running')
            ->update(['status' => 'cancelled', 'error_message' => 'Cancelled by kill switch: '.$reason]);

        // Clear the queue (agents queue)
        Queue::connection('redis')->clear('agents');

        Log::emergency('GLOBAL KILL SWITCH ACTIVATED', [
            'reason' => $reason,
            'activated_by' => $userId,
        ]);
    }

    /**
     * Deactivate global kill switch.
     */
    public function deactivateGlobal(?int $userId = null): void
    {
        $was = Cache::get(self::GLOBAL_KILL_KEY);

        Cache::forget(self::GLOBAL_KILL_KEY);

        Log::warning('Global kill switch deactivated', [
            'was_active_since' => $was['activated_at'] ?? null,
            'deactivated_by' => $userId,
        ]);
    }

    /**
     * Check if global kill switch is active.
     */
    public function isGlobalKillActive(): bool
    {
        $data = Cache::get(self::GLOBAL_KILL_KEY);

        return $data['active'] ?? false;
    }

    /**
     * Get global kill switch status.
     */
    public function getGlobalStatus(): ?array
    {
        return Cache::get(self::GLOBAL_KILL_KEY);
    }

    /**
     * Kill a specific agent.
     */
    public function killAgent(Agent $agent, string $reason, ?int $userId = null): void
    {
        // Set circuit breaker
        $agent->update([
            'circuit_broken_at' => now(),
            'status' => 'disabled',
        ]);

        // Cancel pending runs
        AgentRun::where('agent_id', $agent->id)
            ->where('status', 'running')
            ->update(['status' => 'cancelled', 'error_message' => 'Agent killed: '.$reason]);

        // Cancel pending approvals
        ApprovalRequest::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'notes' => 'Agent killed: '.$reason]);

        Log::warning('Agent killed', [
            'agent' => $agent->slug,
            'reason' => $reason,
            'killed_by' => $userId,
        ]);
    }

    /**
     * Revive a killed agent.
     */
    public function reviveAgent(Agent $agent, ?int $userId = null): void
    {
        $agent->update([
            'circuit_broken_at' => null,
            'status' => 'active',
        ]);

        Log::info('Agent revived', [
            'agent' => $agent->slug,
            'revived_by' => $userId,
        ]);
    }

    /**
     * Check if an agent can run (not killed, not circuit broken, within budget).
     */
    public function canAgentRun(Agent $agent): array
    {
        // Check global kill switch
        if ($this->isGlobalKillActive()) {
            return ['allowed' => false, 'reason' => 'Global kill switch is active'];
        }

        // Check agent-specific kill
        if ($agent->circuit_broken_at !== null) {
            return ['allowed' => false, 'reason' => 'Agent circuit breaker is active'];
        }

        // Check agent status
        if ($agent->status === 'disabled') {
            return ['allowed' => false, 'reason' => 'Agent is disabled'];
        }

        // Check spend limit
        $dailySpend = $this->getAgentDailySpend($agent);
        $limit = $agent->daily_spend_limit ?? self::DEFAULT_DAILY_LIMIT;

        if ($dailySpend >= $limit) {
            return ['allowed' => false, 'reason' => "Daily spend limit reached (\${$dailySpend} of \${$limit})"];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Record agent spend and check if limit exceeded.
     */
    public function recordSpend(Agent $agent, float $amount): bool
    {
        $key = self::SPEND_KEY_PREFIX.$agent->id.':'.now()->format('Y-m-d');

        $newSpend = Cache::increment($key, (int) ($amount * 100)) / 100;

        // Set expiry at end of day
        if ($newSpend == $amount) {
            Cache::put($key, $amount * 100, now()->endOfDay());
        }

        $limit = $agent->daily_spend_limit ?? self::DEFAULT_DAILY_LIMIT;

        if ($newSpend >= $limit) {
            Log::warning('Agent spend limit reached', [
                'agent' => $agent->slug,
                'spend' => $newSpend,
                'limit' => $limit,
            ]);

            return false; // Limit exceeded
        }

        return true;
    }

    /**
     * Get agent's daily spend.
     */
    public function getAgentDailySpend(Agent $agent): float
    {
        $key = self::SPEND_KEY_PREFIX.$agent->id.':'.now()->format('Y-m-d');

        return Cache::get($key, 0) / 100;
    }

    /**
     * Get system-wide spend for today.
     */
    public function getTotalDailySpend(): float
    {
        return AgentRun::whereDate('started_at', today())
            ->sum('cost_usd');
    }

    /**
     * Get emergency status summary.
     */
    public function getStatus(): array
    {
        $globalKill = $this->getGlobalStatus();

        $agents = Agent::all()->map(function ($agent) {
            return [
                'id' => $agent->id,
                'slug' => $agent->slug,
                'name' => $agent->name,
                'status' => $agent->status,
                'circuit_broken' => $agent->circuit_broken_at !== null,
                'circuit_broken_at' => $agent->circuit_broken_at?->toIso8601String(),
                'daily_spend' => $this->getAgentDailySpend($agent),
                'daily_limit' => $agent->daily_spend_limit ?? self::DEFAULT_DAILY_LIMIT,
                'can_run' => $this->canAgentRun($agent),
            ];
        });

        $runningCount = AgentRun::where('status', 'running')->count();
        $pendingApprovals = ApprovalRequest::where('status', 'pending')->count();

        return [
            'global_kill' => [
                'active' => $globalKill['active'] ?? false,
                'activated_at' => $globalKill['activated_at'] ?? null,
                'reason' => $globalKill['reason'] ?? null,
            ],
            'agents' => $agents,
            'running_count' => $runningCount,
            'pending_approvals' => $pendingApprovals,
            'total_daily_spend' => $this->getTotalDailySpend(),
            'queue_size' => Queue::size('agents'),
        ];
    }

    /**
     * Perform health check and auto-trigger circuit breakers.
     */
    public function healthCheck(): array
    {
        $issues = [];

        // Check for agents with consecutive failures
        $agents = Agent::all();

        foreach ($agents as $agent) {
            $recentRuns = AgentRun::where('agent_id', $agent->id)
                ->latest()
                ->limit(5)
                ->get();

            $failures = $recentRuns->where('status', 'failed')->count();

            if ($failures >= 3 && $agent->circuit_broken_at === null) {
                $this->killAgent($agent, 'Auto-killed: 3+ consecutive failures', null);
                $issues[] = "Circuit breaker triggered for {$agent->slug}: {$failures} failures";
            }
        }

        // Check for runaway spend
        $totalSpend = $this->getTotalDailySpend();
        if ($totalSpend > 500) {
            $issues[] = "Warning: Total daily spend is \${$totalSpend}";
        }

        // Check for stuck runs (running > 30 min)
        $stuckRuns = AgentRun::where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(30))
            ->get();

        foreach ($stuckRuns as $run) {
            $run->update(['status' => 'failed', 'error_message' => 'Timeout: exceeded 30 minutes']);
            $issues[] = "Killed stuck run #{$run->id} for agent {$run->agent->slug}";
        }

        return $issues;
    }
}
