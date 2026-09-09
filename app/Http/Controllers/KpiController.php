<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Client;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KpiController extends Controller
{
    /**
     * Get all KPI data for dashboard charts.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'pipeline' => $this->getPipelineMetrics(),
            'clients' => $this->getClientMetrics(),
            'operations' => $this->getOperationsMetrics(),
            'financial' => $this->getFinancialMetrics(),
            'time_tracking' => $this->getTimeTrackingMetrics(),
            'github' => $this->getGitHubMetrics(),
            'trends' => $this->getTrendData(),
        ]);
    }

    /**
     * Pipeline & Revenue metrics.
     */
    protected function getPipelineMetrics(): array
    {
        // Pipeline value by stage
        $pipelineByStage = Lead::select('stage', DB::raw('SUM(deal_value) as total'), DB::raw('COUNT(*) as count'))
            ->whereNotIn('stage', ['won', 'lost'])
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        // Total pipeline value
        $totalPipeline = Lead::whereNotIn('stage', ['won', 'lost'])->sum('deal_value');

        // Weighted pipeline (apply stage probabilities)
        $stageWeights = [
            'new' => 0.1,
            'qualified' => 0.25,
            'proposal' => 0.5,
            'negotiation' => 0.75,
        ];

        $weightedPipeline = Lead::whereNotIn('stage', ['won', 'lost'])
            ->get()
            ->sum(fn ($lead) => ($lead->deal_value ?? 0) * ($stageWeights[$lead->stage] ?? 0));

        // Win rate (won vs won+lost)
        $wonCount = Lead::where('stage', 'won')->count();
        $lostCount = Lead::where('stage', 'lost')->count();
        $totalClosed = $wonCount + $lostCount;
        $winRate = $totalClosed > 0 ? round(($wonCount / $totalClosed) * 100) : 0;

        // Won value this month
        $wonValueMtd = Lead::where('stage', 'won')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum('deal_value');

        // Conversion funnel
        $funnel = [
            ['stage' => 'New', 'count' => $pipelineByStage->get('new')?->count ?? 0],
            ['stage' => 'Qualified', 'count' => $pipelineByStage->get('qualified')?->count ?? 0],
            ['stage' => 'Proposal', 'count' => $pipelineByStage->get('proposal')?->count ?? 0],
            ['stage' => 'Negotiation', 'count' => $pipelineByStage->get('negotiation')?->count ?? 0],
        ];

        return [
            'total_pipeline' => round($totalPipeline, 0),
            'weighted_pipeline' => round($weightedPipeline, 0),
            'win_rate' => $winRate,
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'won_value_mtd' => round($wonValueMtd, 0),
            'funnel' => $funnel,
            'by_stage' => $pipelineByStage->map(fn ($s) => [
                'total' => round($s->total ?? 0, 0),
                'count' => $s->count,
            ]),
        ];
    }

    /**
     * Client health metrics.
     */
    protected function getClientMetrics(): array
    {
        $clients = Client::where('status', 'active')->get();

        // Health distribution
        $healthDistribution = [
            'healthy' => $clients->filter(fn ($c) => $c->health_score >= 8)->count(),
            'at_risk' => $clients->filter(fn ($c) => $c->health_score >= 5 && $c->health_score < 8)->count(),
            'critical' => $clients->filter(fn ($c) => $c->health_score < 5)->count(),
        ];

        // At-risk clients (below 5)
        $atRiskClients = Client::where('status', 'active')
            ->where('health_score', '<', 5)
            ->orderBy('health_score')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'health_score']);

        // Average health
        $avgHealth = $clients->avg('health_score') ?? 0;

        // Client count by status
        $byStatus = Client::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total_active' => $clients->count(),
            'avg_health' => round($avgHealth, 1),
            'health_distribution' => $healthDistribution,
            'at_risk_clients' => $atRiskClients,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Operations metrics (tasks, agents).
     */
    protected function getOperationsMetrics(): array
    {
        // Task metrics this month
        $tasksThisMonth = Task::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $totalTasksMonth = (clone $tasksThisMonth)->count();
        $completedTasksMonth = (clone $tasksThisMonth)->where('status', 'completed')->count();
        $taskCompletionRate = $totalTasksMonth > 0
            ? round(($completedTasksMonth / $totalTasksMonth) * 100)
            : 0;

        // Tasks by status
        $tasksByStatus = Task::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Overdue tasks
        $overdueTasks = Task::whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed'])
            ->count();

        // Agent run metrics
        $agentRunsThisMonth = AgentRun::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);

        $totalAgentRuns = (clone $agentRunsThisMonth)->count();
        $successfulRuns = (clone $agentRunsThisMonth)->where('status', 'completed')->count();
        $agentSuccessRate = $totalAgentRuns > 0
            ? round(($successfulRuns / $totalAgentRuns) * 100)
            : 0;

        // Agent costs (last 30 days)
        $agentCosts = AgentRun::where('created_at', '>=', now()->subDays(30))
            ->sum('cost_usd');

        // Active projects
        $activeProjects = Project::where('status', 'active')->count();
        $projectsByStatus = Project::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'task_completion_rate' => $taskCompletionRate,
            'completed_tasks_month' => $completedTasksMonth,
            'total_tasks_month' => $totalTasksMonth,
            'tasks_by_status' => $tasksByStatus,
            'overdue_tasks' => $overdueTasks,
            'agent_success_rate' => $agentSuccessRate,
            'total_agent_runs' => $totalAgentRuns,
            'agent_costs_30d' => round($agentCosts, 2),
            'active_projects' => $activeProjects,
            'projects_by_status' => $projectsByStatus,
        ];
    }

    /**
     * Historical trend data for sparklines.
     */
    protected function getTrendData(): array
    {
        $days = 14;

        // Pipeline trend (daily total pipeline value)
        $pipelineTrend = collect(range($days - 1, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo)->format('Y-m-d');
            // For simplicity, use current pipeline value (in production, track daily snapshots)
            $value = Lead::whereNotIn('stage', ['won', 'lost'])
                ->where('created_at', '<=', now()->subDays($daysAgo)->endOfDay())
                ->sum('deal_value');

            return ['date' => $date, 'value' => round($value, 0)];
        });

        // Task completion trend (daily completed tasks)
        $taskTrend = collect(range($days - 1, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            $completed = Task::where('status', 'completed')
                ->whereDate('updated_at', $date)
                ->count();

            return ['date' => $date->format('Y-m-d'), 'value' => $completed];
        });

        // Agent cost trend (daily)
        $costTrend = collect(range($days - 1, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            $cost = AgentRun::whereDate('created_at', $date)->sum('cost_usd');

            return ['date' => $date->format('Y-m-d'), 'value' => round($cost, 2)];
        });

        // Overdue trend
        $overdueTrend = collect(range(6, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            $overdue = Task::whereNotNull('due_date')
                ->where('due_date', '<', $date)
                ->whereNotIn('status', ['completed'])
                ->count();

            return ['date' => $date->format('Y-m-d'), 'value' => $overdue];
        });

        return [
            'pipeline' => $pipelineTrend->pluck('value'),
            'tasks' => $taskTrend->pluck('value'),
            'agent_costs' => $costTrend->pluck('value'),
            'overdue' => $overdueTrend->pluck('value'),
            'revenue' => $this->getRevenueTrend($days),
            'hours' => $this->getHoursTrend($days),
        ];
    }

    protected function getFinancialMetrics(): array
    {
        $startOfMonth = now()->startOfMonth();
        $startOfYear = now()->startOfYear();
        $today = now();

        // Revenue from paid invoices this month (payments received MTD)
        $revenueMtd = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startOfMonth, $today])
            ->sum('total');

        // Outstanding AR = unpaid invoices (sent, viewed, partial, overdue)
        $unpaidStatuses = [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ];
        $outstandingInvoices = Invoice::whereIn('status', $unpaidStatuses)->get();
        $outstandingAr = $outstandingInvoices->sum('amount_due');
        $outstandingCount = $outstandingInvoices->count();

        // Overdue invoices (past due date and not paid)
        $overdueInvoices = Invoice::whereIn('status', $unpaidStatuses)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->get();
        $overdueAmount = $overdueInvoices->sum('amount_due');
        $overdueCount = $overdueInvoices->count();

        // Average days to pay (last 30 days of paid invoices)
        $paidInvoices = Invoice::where('status', Invoice::STATUS_PAID)
            ->where('paid_at', '>=', now()->subDays(30))
            ->whereNotNull('issue_date')
            ->whereNotNull('paid_at')
            ->get();
        $avgDaysToPay = $paidInvoices->count() > 0
            ? round($paidInvoices->avg(fn ($inv) => $inv->issue_date->diffInDays($inv->paid_at)))
            : 0;

        // Amount invoiced this month (invoices created/sent this month)
        $invoicedMtd = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_PAID,
            Invoice::STATUS_OVERDUE,
        ])
            ->whereBetween('issue_date', [$startOfMonth, $today])
            ->get();
        $amountInvoicedMtd = $invoicedMtd->sum('total');
        $invoicesIssuedMtd = $invoicedMtd->count();

        // Payments received last month
        $paymentsLastMonth = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->sum('total');

        // Payments received year-to-date
        $paymentsYtd = Invoice::where('status', Invoice::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startOfYear, $today])
            ->sum('total');

        $revenueChange = $paymentsLastMonth > 0
            ? round((($revenueMtd - $paymentsLastMonth) / $paymentsLastMonth) * 100)
            : 0;

        return [
            'revenue_mtd' => round($revenueMtd, 2),
            'revenue_change_pct' => $revenueChange,
            'outstanding_ar' => round($outstandingAr, 2),
            'outstanding_count' => $outstandingCount,
            'overdue_amount' => round($overdueAmount, 2),
            'overdue_count' => $overdueCount,
            'avg_days_to_pay' => $avgDaysToPay,
            'amount_invoiced_mtd' => round($amountInvoicedMtd, 2),
            'invoices_issued_mtd' => $invoicesIssuedMtd,
            'payments_last_month' => round($paymentsLastMonth, 2),
            'payments_ytd' => round($paymentsYtd, 2),
            'invoices_paid_mtd' => Invoice::where('status', Invoice::STATUS_PAID)
                ->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$startOfMonth, $today])
                ->count(),
        ];
    }

    /**
     * Time tracking metrics from TimeEntry.
     */
    protected function getTimeTrackingMetrics(): array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        $startOfWeek = now()->startOfWeek();
        $startOfLastWeek = now()->subWeek()->startOfWeek();
        $endOfLastWeek = now()->subWeek()->endOfWeek();
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();

        // Hours today and yesterday
        $hoursToday = TimeEntry::whereDate('spent_date', $today)->sum('hours');
        $hoursYesterday = TimeEntry::whereDate('spent_date', $yesterday)->sum('hours');

        // Hours this week and last week
        $hoursThisWeek = TimeEntry::where('spent_date', '>=', $startOfWeek)->sum('hours');
        $hoursLastWeek = TimeEntry::whereBetween('spent_date', [$startOfLastWeek, $endOfLastWeek])->sum('hours');

        // Hours this month and last month
        $hoursThisMonth = TimeEntry::whereBetween('spent_date', [$startOfMonth, $endOfMonth])->sum('hours');
        $hoursLastMonth = TimeEntry::whereBetween('spent_date', [$startOfLastMonth, $endOfLastMonth])->sum('hours');

        // Billable hours this month
        $billableHours = TimeEntry::whereBetween('spent_date', [$startOfMonth, $endOfMonth])
            ->where('is_billable', true)
            ->sum('hours');

        // Non-billable hours
        $nonBillableHours = $hoursThisMonth - $billableHours;

        // Utilization rate (billable / total)
        $utilizationRate = $hoursThisMonth > 0
            ? round(($billableHours / $hoursThisMonth) * 100)
            : 0;

        // Billable amount MTD
        $billableAmountMtd = TimeEntry::whereBetween('spent_date', [$startOfMonth, $endOfMonth])
            ->where('is_billable', true)
            ->get()
            ->sum('billable_amount');

        // Top clients by hours (active clients only)
        $topClientsByHours = TimeEntry::whereBetween('spent_date', [$startOfMonth, $endOfMonth])
            ->whereHas('client', fn ($q) => $q->where('status', 'active'))
            ->select('client_id', DB::raw('SUM(hours) as total_hours'))
            ->groupBy('client_id')
            ->orderByDesc('total_hours')
            ->limit(5)
            ->with('client:id,name,slug')
            ->get()
            ->map(fn ($entry) => [
                'client' => $entry->client?->name ?? 'Unassigned',
                'slug' => $entry->client?->slug,
                'hours' => round($entry->total_hours, 1),
            ]);

        // Unbilled hours (billable but not yet billed)
        $unbilledHours = TimeEntry::where('is_billable', true)
            ->where('is_billed', false)
            ->sum('hours');

        // Unbilled amount
        $unbilledAmount = TimeEntry::where('is_billable', true)
            ->where('is_billed', false)
            ->get()
            ->sum('billable_amount');

        return [
            'hours_today' => round($hoursToday, 2),
            'hours_yesterday' => round($hoursYesterday, 2),
            'hours_this_week' => round($hoursThisWeek, 2),
            'hours_last_week' => round($hoursLastWeek, 2),
            'hours_mtd' => round($hoursThisMonth, 2),
            'hours_last_month' => round($hoursLastMonth, 2),
            'billable_hours' => round($billableHours, 1),
            'non_billable_hours' => round($nonBillableHours, 1),
            'utilization_rate' => $utilizationRate,
            'billable_amount_mtd' => round($billableAmountMtd, 2),
            'unbilled_hours' => round($unbilledHours, 1),
            'unbilled_amount' => round($unbilledAmount, 2),
            'top_clients' => $topClientsByHours,
        ];
    }

    /**
     * GitHub metrics for engineering velocity.
     */
    protected function getGitHubMetrics(): array
    {
        // Open issues
        $openIssues = GitHubIssue::where('state', 'open')->count();

        // Issues closed this week
        $issuesClosedThisWeek = GitHubIssue::where('state', 'closed')
            ->where('closed_at', '>=', now()->startOfWeek())
            ->count();

        // Agent tasks (issues with agent label)
        $agentTasks = GitHubIssue::where('state', 'open')
            ->where(function ($q) {
                $q->whereJsonContains('labels', 'agent')
                    ->orWhereJsonContains('labels', 'agent-task');
            })
            ->count();

        // PRs merged this week
        $prsMergedThisWeek = GitHubPullRequest::where('merged_at', '>=', now()->startOfWeek())
            ->count();

        // Open PRs awaiting review
        $openPrs = GitHubPullRequest::where('state', 'open')
            ->whereNull('merged_at')
            ->count();

        // Average PR cycle time (days from open to merge)
        $mergedPrs = GitHubPullRequest::whereNotNull('merged_at')
            ->where('merged_at', '>=', now()->subDays(30))
            ->get();
        $avgPrCycleTime = $mergedPrs->count() > 0
            ? round($mergedPrs->avg(fn ($pr) => $pr->created_at->diffInHours($pr->merged_at) / 24), 1)
            : 0;

        // Issues by label (top 5)
        $issuesByLabel = GitHubIssue::where('state', 'open')
            ->get()
            ->flatMap(fn ($issue) => $issue->labels ?? [])
            ->countBy()
            ->sortDesc()
            ->take(5);

        return [
            'open_issues' => $openIssues,
            'issues_closed_week' => $issuesClosedThisWeek,
            'agent_tasks' => $agentTasks,
            'prs_merged_week' => $prsMergedThisWeek,
            'open_prs' => $openPrs,
            'avg_pr_cycle_days' => $avgPrCycleTime,
            'issues_by_label' => $issuesByLabel,
        ];
    }

    protected function getRevenueTrend(int $days): array
    {
        return collect(range($days - 1, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            $revenue = Invoice::where('status', Invoice::STATUS_PAID)
                ->whereDate('paid_at', $date)
                ->sum('total');

            return round($revenue, 0);
        })->values()->toArray();
    }

    /**
     * Hours tracked trend for sparklines.
     */
    protected function getHoursTrend(int $days): array
    {
        return collect(range($days - 1, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            $hours = TimeEntry::whereDate('spent_date', $date)->sum('hours');

            return round($hours, 1);
        })->values()->toArray();
    }
}
