<?php

namespace App\Services\Slack;

use App\Models\AgentRun;
use App\Models\Project;
use App\Services\Agents\AgentExecutor;
use Illuminate\Database\Eloquent\Collection;

class SlackAgentRunService
{
    public function __construct(
        private AgentExecutor $executor,
    ) {}

    /**
     * @return Collection<int, AgentRun>
     */
    public function listProjectRuns(Project $project, ?string $filter = 'active', int $limit = 10): Collection
    {
        $statuses = $this->statusesForFilter($filter);

        return AgentRun::query()
            ->with('agent')
            ->where('project_id', $project->id)
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function findProjectRun(Project $project, int $runId): ?AgentRun
    {
        return AgentRun::query()
            ->with('agent')
            ->where('project_id', $project->id)
            ->whereKey($runId)
            ->first();
    }

    public function normalizeFilter(?string $filter): string
    {
        $normalized = strtolower(trim((string) $filter));

        return match ($normalized) {
            '', 'active', 'open', 'current' => 'active',
            'running', 'failed', 'completed', 'cancelled', 'awaiting_input', 'pending_approval', 'all' => $normalized,
            default => 'active',
        };
    }

    public function isCancellable(AgentRun $run): bool
    {
        return in_array($run->status, [
            'pending',
            AgentRun::STATUS_RUNNING,
            AgentRun::STATUS_PENDING_APPROVAL,
            AgentRun::STATUS_AWAITING_INPUT,
        ], true);
    }

    public function cancelRun(AgentRun $run, string $reason = 'Cancelled from Slack'): AgentRun
    {
        return $this->executor->cancel($run, $reason);
    }

    /**
     * @return list<string>|null
     */
    private function statusesForFilter(?string $filter): ?array
    {
        return match ($this->normalizeFilter($filter)) {
            'active' => [
                AgentRun::STATUS_RUNNING,
                AgentRun::STATUS_PENDING_APPROVAL,
                AgentRun::STATUS_AWAITING_INPUT,
            ],
            'running' => [AgentRun::STATUS_RUNNING],
            'failed' => [AgentRun::STATUS_FAILED],
            'completed' => [AgentRun::STATUS_COMPLETED],
            'cancelled' => [AgentRun::STATUS_CANCELLED],
            'awaiting_input' => [AgentRun::STATUS_AWAITING_INPUT],
            'pending_approval' => [AgentRun::STATUS_PENDING_APPROVAL],
            'all' => null,
            default => [
                AgentRun::STATUS_RUNNING,
                AgentRun::STATUS_PENDING_APPROVAL,
                AgentRun::STATUS_AWAITING_INPUT,
            ],
        };
    }
}
