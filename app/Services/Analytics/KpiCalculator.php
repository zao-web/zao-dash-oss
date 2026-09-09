<?php

namespace App\Services\Analytics;

use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\QboInvoice;
use App\Models\StrategicGoal;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calculates KPIs for the dashboard and reporting.
 *
 * All methods support optional date ranges and caching.
 */
class KpiCalculator
{
    /**
     * Cache TTL in seconds.
     */
    protected int $cacheTtl = 300; // 5 minutes

    /**
     * Get all dashboard KPIs.
     */
    public function getDashboardKpis(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        $cacheKey = "dashboard_kpis_{$from->format('Y-m-d')}_{$to->format('Y-m-d')}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($from, $to) {
            return [
                'revenue' => $this->getRevenue($from, $to),
                'projects' => $this->getProjectStats($from, $to),
                'clients' => $this->getClientStats($from, $to),
                'pipeline' => $this->getPipelineStats($from, $to),
                'team' => $this->getTeamStats($from, $to),
                'agents' => $this->getAgentStats($from, $to),
                'goals' => $this->getGoalProgress(),
            ];
        });
    }

    /**
     * Revenue KPIs.
     */
    public function getRevenue(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        // Try QuickBooks first, fallback to Harvest
        $invoicedMtd = $this->getInvoicedRevenue($from, $to);
        $paidMtd = $this->getPaidRevenue($from, $to);

        // Previous period for comparison
        $daysSoFar = $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($daysSoFar);
        $prevTo = $to->copy()->subDays($daysSoFar);

        $invoicedPrev = $this->getInvoicedRevenue($prevFrom, $prevTo);
        $paidPrev = $this->getPaidRevenue($prevFrom, $prevTo);

        return [
            'invoiced_mtd' => $invoicedMtd,
            'paid_mtd' => $paidMtd,
            'invoiced_change_pct' => $this->percentChange($invoicedPrev, $invoicedMtd),
            'paid_change_pct' => $this->percentChange($paidPrev, $paidMtd),
            'outstanding' => $this->getOutstandingRevenue(),
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ];
    }

    /**
     * Project KPIs.
     */
    public function getProjectStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $activeProjects = Project::where('status', 'active')->count();
        $completedThisPeriod = Project::where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        $atRisk = Project::where('status', 'active')
            ->where(function ($query) {
                $query->where('health_score', '<', 60)
                    ->orWhere('deadline', '<', now()->addDays(7));
            })
            ->count();

        return [
            'active' => $activeProjects,
            'completed_period' => $completedThisPeriod,
            'at_risk' => $atRisk,
            'average_health' => $this->getAverageProjectHealth(),
        ];
    }

    /**
     * Client KPIs.
     */
    public function getClientStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        $totalClients = Client::where('status', 'active')->count();
        $newClients = Client::whereBetween('created_at', [$from, $to])->count();

        // Client health distribution
        $healthDistribution = Client::where('status', 'active')
            ->select(DB::raw('
                CASE
                    WHEN health_score >= 80 THEN "healthy"
                    WHEN health_score >= 60 THEN "moderate"
                    ELSE "at_risk"
                END as health_category
            '))
            ->selectRaw('COUNT(*) as count')
            ->groupBy('health_category')
            ->pluck('count', 'health_category')
            ->toArray();

        return [
            'total_active' => $totalClients,
            'new_period' => $newClients,
            'health_distribution' => [
                'healthy' => $healthDistribution['healthy'] ?? 0,
                'moderate' => $healthDistribution['moderate'] ?? 0,
                'at_risk' => $healthDistribution['at_risk'] ?? 0,
            ],
            'average_lifetime_value' => $this->getAverageClientLifetimeValue(),
        ];
    }

    /**
     * Sales pipeline KPIs.
     */
    public function getPipelineStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        // Pipeline value = sum of deal values for active leads (not won/lost)
        $pipelineValue = Lead::whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])
            ->whereNull('converted_at')
            ->sum('deal_value');

        $leadsCreated = Lead::whereBetween('created_at', [$from, $to])->count();

        // Won leads are those with stage='won' and converted_at in the period
        $leadsWon = Lead::where('stage', 'won')
            ->whereBetween('converted_at', [$from, $to])
            ->count();

        // Lost leads are those with stage='lost' and updated_at in the period
        $leadsLost = Lead::where('stage', 'lost')
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        $totalClosed = $leadsWon + $leadsLost;
        $winRate = $totalClosed > 0 ? round(($leadsWon / $totalClosed) * 100, 1) : 0;

        return [
            'pipeline_value' => $pipelineValue,
            'leads_created' => $leadsCreated,
            'leads_won' => $leadsWon,
            'leads_lost' => $leadsLost,
            'win_rate' => $winRate,
            'average_deal_size' => $this->getAverageDealSize($from, $to),
            'average_sales_cycle_days' => $this->getAverageSalesCycle($from, $to),
        ];
    }

    /**
     * Team KPIs (time tracking).
     */
    public function getTeamStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        $totalHours = TimeEntry::whereBetween('date', [$from, $to])
            ->sum('hours');

        $billableHours = TimeEntry::whereBetween('date', [$from, $to])
            ->where('billable', true)
            ->sum('hours');

        $utilizationRate = $totalHours > 0
            ? round(($billableHours / $totalHours) * 100, 1)
            : 0;

        return [
            'total_hours' => round($totalHours, 1),
            'billable_hours' => round($billableHours, 1),
            'utilization_rate' => $utilizationRate,
            'by_project' => $this->getHoursByProject($from, $to),
        ];
    }

    /**
     * Agent KPIs.
     */
    public function getAgentStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->startOfMonth();
        $to = $to ?? now();

        $totalRuns = AgentRun::whereBetween('created_at', [$from, $to])->count();
        $successfulRuns = AgentRun::whereBetween('created_at', [$from, $to])
            ->where('status', 'completed')
            ->count();
        $failedRuns = AgentRun::whereBetween('created_at', [$from, $to])
            ->where('status', 'failed')
            ->count();

        $totalCost = AgentRun::whereBetween('created_at', [$from, $to])
            ->sum('cost_usd');

        $pendingApprovals = ApprovalRequest::where('status', 'pending')->count();

        $successRate = $totalRuns > 0
            ? round(($successfulRuns / $totalRuns) * 100, 1)
            : 0;

        return [
            'total_runs' => $totalRuns,
            'successful' => $successfulRuns,
            'failed' => $failedRuns,
            'success_rate' => $successRate,
            'total_cost' => round($totalCost, 2),
            'pending_approvals' => $pendingApprovals,
            'top_agents' => $this->getTopAgents($from, $to),
        ];
    }

    /**
     * Goal progress.
     */
    public function getGoalProgress(): array
    {
        $activeGoal = StrategicGoal::where('status', 'active')
            ->where('fiscal_year', now()->year)
            ->first();

        if (! $activeGoal) {
            return [
                'has_goal' => false,
            ];
        }

        $currentRevenue = $this->getInvoicedRevenue(
            Carbon::create($activeGoal->fiscal_year, 1, 1),
            now()
        );

        $targetRevenue = $activeGoal->revenue_target;
        $progressPct = $targetRevenue > 0
            ? round(($currentRevenue / $targetRevenue) * 100, 1)
            : 0;

        // Calculate expected progress based on time elapsed
        $yearStart = Carbon::create($activeGoal->fiscal_year, 1, 1);
        $yearEnd = Carbon::create($activeGoal->fiscal_year, 12, 31);
        $daysPassed = $yearStart->diffInDays(now());
        $totalDays = $yearStart->diffInDays($yearEnd);
        $expectedProgressPct = round(($daysPassed / $totalDays) * 100, 1);

        return [
            'has_goal' => true,
            'target_revenue' => $targetRevenue,
            'current_revenue' => $currentRevenue,
            'progress_pct' => $progressPct,
            'expected_progress_pct' => $expectedProgressPct,
            'on_track' => $progressPct >= $expectedProgressPct * 0.9, // 10% buffer
            'margin_target_pct' => $activeGoal->margin_target_pct,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function getInvoicedRevenue(Carbon $from, Carbon $to): float
    {
        // Try QuickBooks first
        $qboRevenue = QboInvoice::whereBetween('invoice_date', [$from, $to])
            ->sum('total_amount');

        if ($qboRevenue > 0) {
            return $qboRevenue;
        }

        // Fallback to Harvest
        return HarvestInvoice::whereBetween('issue_date', [$from, $to])
            ->sum('amount');
    }

    protected function getPaidRevenue(Carbon $from, Carbon $to): float
    {
        $qboPaid = QboInvoice::where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total_amount');

        if ($qboPaid > 0) {
            return $qboPaid;
        }

        return HarvestInvoice::where('state', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    protected function getOutstandingRevenue(): float
    {
        $qboOutstanding = QboInvoice::whereIn('status', ['sent', 'overdue'])
            ->sum('balance');

        if ($qboOutstanding > 0) {
            return $qboOutstanding;
        }

        return HarvestInvoice::whereIn('state', ['open', 'draft'])
            ->sum('due_amount');
    }

    protected function getAverageProjectHealth(): float
    {
        return Project::where('status', 'active')
            ->whereNotNull('health_score')
            ->avg('health_score') ?? 0;
    }

    protected function getAverageClientLifetimeValue(): float
    {
        return Client::where('status', 'active')
            ->whereNotNull('lifetime_value')
            ->avg('lifetime_value') ?? 0;
    }

    protected function getAverageDealSize(Carbon $from, Carbon $to): float
    {
        return Lead::where('stage', 'won')
            ->whereBetween('converted_at', [$from, $to])
            ->avg('deal_value') ?? 0;
    }

    protected function getAverageSalesCycle(Carbon $from, Carbon $to): int
    {
        $leads = Lead::where('stage', 'won')
            ->whereBetween('converted_at', [$from, $to])
            ->whereNotNull('created_at')
            ->whereNotNull('converted_at')
            ->get();

        if ($leads->isEmpty()) {
            return 0;
        }

        $totalDays = $leads->sum(function ($lead) {
            return $lead->created_at->diffInDays($lead->converted_at);
        });

        return (int) round($totalDays / $leads->count());
    }

    protected function getHoursByProject(Carbon $from, Carbon $to): array
    {
        return TimeEntry::whereBetween('date', [$from, $to])
            ->select('project_id')
            ->selectRaw('SUM(hours) as total_hours')
            ->groupBy('project_id')
            ->with('project:id,name')
            ->orderByDesc('total_hours')
            ->limit(5)
            ->get()
            ->map(fn ($entry) => [
                'project' => $entry->project?->name ?? 'Unknown',
                'hours' => round($entry->total_hours, 1),
            ])
            ->toArray();
    }

    protected function getTopAgents(Carbon $from, Carbon $to): array
    {
        return AgentRun::whereBetween('created_at', [$from, $to])
            ->select('agent_id')
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful')
            ->groupBy('agent_id')
            ->orderByDesc('runs')
            ->limit(5)
            ->get()
            ->map(fn ($run) => [
                'agent_id' => $run->agent_id,
                'runs' => $run->runs,
                'success_rate' => $run->runs > 0
                    ? round(($run->successful / $run->runs) * 100, 1)
                    : 0,
            ])
            ->toArray();
    }

    protected function percentChange(float $previous, float $current): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Clear cached KPIs.
     */
    public function clearCache(): void
    {
        Cache::forget('dashboard_kpis_*');
    }
}
