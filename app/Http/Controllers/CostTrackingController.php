<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CostTrackingController extends Controller
{
    /**
     * Display the cost tracking dashboard.
     */
    public function index(Request $request)
    {
        $period = $request->get('period', '30'); // days

        // Overall stats
        $overallStats = $this->getOverallStats((int) $period);

        // Cost by agent
        $costByAgent = $this->getCostByAgent((int) $period);

        // Daily costs for chart
        $dailyCosts = $this->getDailyCosts((int) $period);

        // Cost by model
        $costByModel = $this->getCostByModel((int) $period);

        // Cost by invocation source
        $costBySource = $this->getCostBySource((int) $period);

        // Recent expensive runs
        $expensiveRuns = $this->getExpensiveRuns(10);

        return Inertia::render('Costs/Index', [
            'period' => (int) $period,
            'overallStats' => $overallStats,
            'costByAgent' => $costByAgent,
            'dailyCosts' => $dailyCosts,
            'costByModel' => $costByModel,
            'costBySource' => $costBySource,
            'expensiveRuns' => $expensiveRuns,
        ]);
    }

    /**
     * Get overall cost statistics.
     */
    protected function getOverallStats(int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $stats = AgentRun::where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_runs,
                SUM(cost_usd) as total_cost,
                AVG(cost_usd) as avg_cost,
                MAX(cost_usd) as max_cost,
                SUM(COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)) as total_tokens
            ')
            ->first();

        // Compare with previous period
        $previousStart = $startDate->copy()->subDays($days);
        $previousStats = AgentRun::whereBetween('created_at', [$previousStart, $startDate])
            ->selectRaw('SUM(cost_usd) as total_cost')
            ->first();

        $previousCost = $previousStats->total_cost ?? 0;
        $currentCost = $stats->total_cost ?? 0;
        $costChange = $previousCost > 0
            ? (($currentCost - $previousCost) / $previousCost) * 100
            : 0;

        return [
            'total_runs' => $stats->total_runs ?? 0,
            'total_cost' => round($currentCost, 2),
            'avg_cost' => round($stats->avg_cost ?? 0, 4),
            'max_cost' => round($stats->max_cost ?? 0, 4),
            'total_tokens' => $stats->total_tokens ?? 0,
            'cost_change_percent' => round($costChange, 1),
        ];
    }

    /**
     * Get cost breakdown by agent.
     */
    protected function getCostByAgent(int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        return Agent::withCount(['runs' => function ($query) use ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }])
            ->withSum(['runs' => function ($query) use ($startDate) {
                $query->where('created_at', '>=', $startDate);
            }], 'cost_usd')
            ->get()
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'runs_count' => $agent->runs_count,
                'total_cost' => round($agent->runs_sum_cost_usd ?? 0, 2),
            ])
            ->sortByDesc('total_cost')
            ->values()
            ->toArray();
    }

    /**
     * Get daily costs for chart.
     */
    protected function getDailyCosts(int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $dailyCosts = AgentRun::where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, SUM(cost_usd) as cost, COUNT(*) as runs')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill in missing days with zeros
        $result = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'cost' => round($dailyCosts[$date]->cost ?? 0, 2),
                'runs' => $dailyCosts[$date]->runs ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Get cost breakdown by model.
     */
    protected function getCostByModel(int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        return AgentRun::where('agent_runs.created_at', '>=', $startDate)
            ->join('agents', 'agent_runs.agent_id', '=', 'agents.id')
            ->selectRaw('agents.model, SUM(agent_runs.cost_usd) as cost, COUNT(*) as runs')
            ->groupBy('agents.model')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => [
                'model' => $row->model ?? 'unknown',
                'cost' => round($row->cost ?? 0, 2),
                'runs' => $row->runs,
            ])
            ->toArray();
    }

    /**
     * Get cost breakdown by invocation source.
     */
    protected function getCostBySource(int $days): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        return AgentRun::where('created_at', '>=', $startDate)
            ->selectRaw('invocation_source, SUM(cost_usd) as cost, COUNT(*) as runs')
            ->groupBy('invocation_source')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->invocation_source ?? 'unknown',
                'cost' => round($row->cost ?? 0, 2),
                'runs' => $row->runs,
            ])
            ->toArray();
    }

    /**
     * Get most expensive recent runs.
     */
    protected function getExpensiveRuns(int $limit): array
    {
        return AgentRun::with('agent:id,name,slug')
            ->whereNotNull('cost_usd')
            ->where('cost_usd', '>', 0)
            ->orderByDesc('cost_usd')
            ->limit($limit)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'agent_name' => $run->agent->name ?? 'Unknown',
                'agent_slug' => $run->agent->slug ?? '',
                'cost_usd' => round($run->cost_usd, 4),
                'tokens_used' => ($run->input_tokens ?? 0) + ($run->output_tokens ?? 0),
                'status' => $run->status,
                'created_at' => $run->created_at->toIso8601String(),
                'invocation_source' => $run->invocation_source,
            ])
            ->toArray();
    }

    /**
     * Get cost data for API (for dashboard widget).
     */
    public function summary(Request $request)
    {
        $period = $request->get('period', '30');
        $stats = $this->getOverallStats((int) $period);

        return response()->json($stats);
    }
}
