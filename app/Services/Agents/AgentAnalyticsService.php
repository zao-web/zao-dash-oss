<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agent Analytics Service - tracks cost, usage, performance, and ROI metrics.
 */
class AgentAnalyticsService
{
    /**
     * Get comprehensive analytics for all agents.
     */
    public function getDashboardAnalytics(int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        return [
            'summary' => $this->getSummaryMetrics($startDate),
            'cost_trend' => $this->getCostTrend($startDate),
            'usage_trend' => $this->getUsageTrend($startDate),
            'by_agent' => $this->getByAgentMetrics($startDate),
            'by_source' => $this->getBySourceMetrics($startDate),
            'top_performers' => $this->getTopPerformers($startDate),
            'failure_analysis' => $this->getFailureAnalysis($startDate),
            'token_usage' => $this->getTokenUsage($startDate),
        ];
    }

    /**
     * Summary metrics for the period.
     */
    public function getSummaryMetrics(Carbon $startDate): array
    {
        $runs = AgentRun::where('created_at', '>=', $startDate);
        $previousPeriod = AgentRun::whereBetween('created_at', [
            $startDate->copy()->subDays($startDate->diffInDays(now())),
            $startDate,
        ]);

        $totalRuns = $runs->count();
        $totalCost = $runs->sum('cost_usd');
        $successfulRuns = $runs->clone()->where('status', 'completed')->count();
        $failedRuns = $runs->clone()->where('status', 'failed')->count();

        $prevRuns = $previousPeriod->count();
        $prevCost = $previousPeriod->sum('cost_usd');

        return [
            'total_runs' => $totalRuns,
            'total_cost' => round($totalCost, 2),
            'successful_runs' => $successfulRuns,
            'failed_runs' => $failedRuns,
            'success_rate' => $totalRuns > 0 ? round(($successfulRuns / $totalRuns) * 100, 1) : 0,
            'avg_cost_per_run' => $totalRuns > 0 ? round($totalCost / $totalRuns, 4) : 0,
            'active_agents' => Agent::where('status', 'active')->count(),
            'runs_change' => $prevRuns > 0 ? round((($totalRuns - $prevRuns) / $prevRuns) * 100, 1) : 0,
            'cost_change' => $prevCost > 0 ? round((($totalCost - $prevCost) / $prevCost) * 100, 1) : 0,
        ];
    }

    /**
     * Daily cost trend.
     */
    public function getCostTrend(Carbon $startDate): array
    {
        $data = AgentRun::where('created_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(cost_usd) as cost'),
                DB::raw('COUNT(*) as runs')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $data->map(fn ($row) => [
            'date' => $row->date,
            'cost' => round($row->cost, 4),
            'runs' => $row->runs,
        ])->values()->all();
    }

    /**
     * Daily usage trend by status.
     */
    public function getUsageTrend(Carbon $startDate): array
    {
        $data = AgentRun::where('created_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'status',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get();

        // Pivot by date
        $byDate = [];
        foreach ($data as $row) {
            if (! isset($byDate[$row->date])) {
                $byDate[$row->date] = [
                    'date' => $row->date,
                    'completed' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                    'running' => 0,
                ];
            }
            $byDate[$row->date][$row->status] = $row->count;
        }

        return array_values($byDate);
    }

    /**
     * Metrics by agent.
     */
    public function getByAgentMetrics(Carbon $startDate): array
    {
        return AgentRun::where('agent_runs.created_at', '>=', $startDate)
            ->join('agents', 'agent_runs.agent_id', '=', 'agents.id')
            ->select(
                'agents.id',
                'agents.name',
                'agents.slug',
                DB::raw('COUNT(*) as total_runs'),
                DB::raw('SUM(CASE WHEN agent_runs.status = "completed" THEN 1 ELSE 0 END) as successful'),
                DB::raw('SUM(CASE WHEN agent_runs.status = "failed" THEN 1 ELSE 0 END) as failed'),
                DB::raw('SUM(agent_runs.cost_usd) as total_cost'),
                DB::raw('AVG(agent_runs.cost_usd) as avg_cost'),
                DB::raw('SUM(agent_runs.input_tokens) as total_input_tokens'),
                DB::raw('SUM(agent_runs.output_tokens) as total_output_tokens')
            )
            ->groupBy('agents.id', 'agents.name', 'agents.slug')
            ->orderByDesc('total_runs')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'total_runs' => $row->total_runs,
                'successful' => $row->successful,
                'failed' => $row->failed,
                'success_rate' => $row->total_runs > 0
                    ? round(($row->successful / $row->total_runs) * 100, 1)
                    : 0,
                'total_cost' => round($row->total_cost, 4),
                'avg_cost' => round($row->avg_cost, 4),
                'total_tokens' => ($row->total_input_tokens ?? 0) + ($row->total_output_tokens ?? 0),
            ])
            ->all();
    }

    /**
     * Metrics by invocation source.
     */
    public function getBySourceMetrics(Carbon $startDate): array
    {
        return AgentRun::where('created_at', '>=', $startDate)
            ->select(
                'invocation_source',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(cost_usd) as cost'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful')
            )
            ->groupBy('invocation_source')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->invocation_source ?? 'unknown',
                'count' => $row->count,
                'cost' => round($row->cost, 4),
                'success_rate' => $row->count > 0
                    ? round(($row->successful / $row->count) * 100, 1)
                    : 0,
            ])
            ->all();
    }

    /**
     * Top performing agents by success rate and volume.
     */
    public function getTopPerformers(Carbon $startDate, int $limit = 5): array
    {
        return AgentRun::where('agent_runs.created_at', '>=', $startDate)
            ->join('agents', 'agent_runs.agent_id', '=', 'agents.id')
            ->select(
                'agents.id',
                'agents.name',
                'agents.slug',
                DB::raw('COUNT(*) as total_runs'),
                DB::raw('SUM(CASE WHEN agent_runs.status = "completed" THEN 1 ELSE 0 END) as successful'),
                DB::raw('SUM(agent_runs.cost_usd) as total_cost')
            )
            ->groupBy('agents.id', 'agents.name', 'agents.slug')
            ->havingRaw('COUNT(*) >= 5') // Minimum 5 runs to qualify
            ->orderByRaw('(SUM(CASE WHEN agent_runs.status = "completed" THEN 1 ELSE 0 END) / COUNT(*)) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'total_runs' => $row->total_runs,
                'success_rate' => round(($row->successful / $row->total_runs) * 100, 1),
                'total_cost' => round($row->total_cost, 4),
            ])
            ->all();
    }

    /**
     * Failure analysis - common failure patterns.
     */
    public function getFailureAnalysis(Carbon $startDate): array
    {
        $failures = AgentRun::where('created_at', '>=', $startDate)
            ->where('status', 'failed')
            ->with('agent:id,name,slug')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Group by error type
        $byError = [];
        foreach ($failures as $run) {
            $error = $this->categorizeError($run->output);
            if (! isset($byError[$error])) {
                $byError[$error] = ['count' => 0, 'agents' => []];
            }
            $byError[$error]['count']++;
            $agentSlug = $run->agent?->slug ?? 'unknown';
            if (! in_array($agentSlug, $byError[$error]['agents'])) {
                $byError[$error]['agents'][] = $agentSlug;
            }
        }

        // Sort by count
        arsort($byError);

        return [
            'total_failures' => $failures->count(),
            'by_error_type' => array_map(fn ($k, $v) => [
                'error' => $k,
                'count' => $v['count'],
                'affected_agents' => $v['agents'],
            ], array_keys($byError), array_values($byError)),
            'recent_failures' => $failures->take(10)->map(fn ($run) => [
                'id' => $run->id,
                'agent' => $run->agent?->name ?? 'Unknown',
                'error' => $this->extractErrorMessage($run->output),
                'at' => $run->created_at->diffForHumans(),
            ])->all(),
        ];
    }

    /**
     * Token usage analytics.
     */
    public function getTokenUsage(Carbon $startDate): array
    {
        $totals = AgentRun::where('created_at', '>=', $startDate)
            ->select(
                DB::raw('SUM(input_tokens) as input'),
                DB::raw('SUM(output_tokens) as output'),
                DB::raw('SUM(input_tokens + output_tokens) as total')
            )
            ->first();

        $byModel = Agent::join('agent_runs', 'agents.id', '=', 'agent_runs.agent_id')
            ->where('agent_runs.created_at', '>=', $startDate)
            ->select(
                'agents.model',
                DB::raw('SUM(agent_runs.input_tokens) as input'),
                DB::raw('SUM(agent_runs.output_tokens) as output'),
                DB::raw('SUM(agent_runs.cost_usd) as cost')
            )
            ->groupBy('agents.model')
            ->get();

        return [
            'total_input' => $totals->input ?? 0,
            'total_output' => $totals->output ?? 0,
            'total' => $totals->total ?? 0,
            'by_model' => $byModel->map(fn ($row) => [
                'model' => $row->model ?? 'unknown',
                'input_tokens' => $row->input ?? 0,
                'output_tokens' => $row->output ?? 0,
                'cost' => round($row->cost ?? 0, 4),
            ])->all(),
        ];
    }

    /**
     * Get analytics for a specific agent.
     */
    public function getAgentAnalytics(Agent $agent, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $runs = AgentRun::where('agent_id', $agent->id)
            ->where('created_at', '>=', $startDate);

        $dailyStats = AgentRun::where('agent_id', $agent->id)
            ->where('created_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(cost_usd) as cost'),
                DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $recentRuns = AgentRun::where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'status' => $agent->status,
                'model' => $agent->model,
            ],
            'summary' => [
                'total_runs' => $runs->count(),
                'successful' => $runs->clone()->where('status', 'completed')->count(),
                'failed' => $runs->clone()->where('status', 'failed')->count(),
                'total_cost' => round($runs->sum('cost_usd'), 4),
                'avg_cost' => round($runs->avg('cost_usd') ?? 0, 4),
                'total_tokens' => $runs->sum('input_tokens') + $runs->sum('output_tokens'),
            ],
            'daily' => $dailyStats->map(fn ($row) => [
                'date' => $row->date,
                'runs' => $row->runs,
                'cost' => round($row->cost, 4),
                'success_rate' => $row->runs > 0
                    ? round(($row->successful / $row->runs) * 100, 1)
                    : 0,
            ])->all(),
            'recent_runs' => $recentRuns->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'cost' => round($run->cost_usd ?? 0, 4),
                'duration' => $run->started_at && $run->completed_at
                    ? $run->completed_at->diffInSeconds($run->started_at)
                    : null,
                'source' => $run->invocation_source,
                'at' => $run->created_at->diffForHumans(),
            ])->all(),
        ];
    }

    /**
     * Categorize error into types.
     */
    protected function categorizeError(?array $output): string
    {
        if (! $output) {
            return 'unknown';
        }

        $error = strtolower($output['error'] ?? '');

        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($error, 'rate limit') || str_contains($error, '429')) {
            return 'rate_limit';
        }
        if (str_contains($error, 'api') || str_contains($error, '500') || str_contains($error, '503')) {
            return 'api_error';
        }
        if (str_contains($error, 'token') || str_contains($error, 'context')) {
            return 'token_limit';
        }
        if (str_contains($error, 'permission') || str_contains($error, 'auth')) {
            return 'permission';
        }
        if (str_contains($error, 'tool') || str_contains($error, 'function')) {
            return 'tool_error';
        }

        return 'other';
    }

    /**
     * Extract error message from output.
     */
    protected function extractErrorMessage(?array $output): string
    {
        if (! $output) {
            return 'Unknown error';
        }

        $error = $output['error'] ?? null;
        if (is_string($error)) {
            return strlen($error) > 100 ? substr($error, 0, 100).'...' : $error;
        }

        return 'Unknown error';
    }
}
