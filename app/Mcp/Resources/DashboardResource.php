<?php

namespace App\Mcp\Resources;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class DashboardResource extends Resource
{
    protected string $name = 'dashboard';

    protected string $title = 'Dashboard KPIs';

    protected string $description = 'Current dashboard KPIs and business metrics';

    protected string $uri = 'dashboard://kpis';

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        $monthStart = now()->startOfMonth();
        $today = now();

        $revenueMtd = (float) HarvestInvoice::where('state', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$monthStart, $today])
            ->sum('amount');

        $hoursTrackedMtd = (float) TimeEntry::whereBetween('spent_date', [$monthStart, $today])->sum('hours');

        $tasksByStatus = Task::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pipelineValue = Lead::whereIn('stage', ['new', 'qualified', 'proposal', 'negotiation'])->sum('deal_value');

        return Response::json([
            'revenue' => [
                'mtd' => $revenueMtd,
                'currency' => 'USD',
            ],
            'time' => [
                'hours_tracked_mtd' => round($hoursTrackedMtd, 1),
            ],
            'clients' => [
                'active' => Client::where('status', 'active')->count(),
                'total' => Client::count(),
                'avg_health_score' => round(Client::where('status', 'active')->avg('health_score') ?? 0, 1),
            ],
            'projects' => [
                'active' => Project::where('status', 'active')->count(),
                'total' => Project::count(),
            ],
            'tasks' => [
                'pending' => $tasksByStatus['pending'] ?? 0,
                'in_progress' => $tasksByStatus['in_progress'] ?? 0,
                'review' => $tasksByStatus['review'] ?? 0,
                'completed_today' => Task::where('status', 'completed')
                    ->whereDate('updated_at', today())
                    ->count(),
                'overdue' => Task::where('status', '!=', 'completed')
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', today())
                    ->count(),
            ],
            'leads' => [
                'pipeline_value' => $pipelineValue,
                'new' => Lead::where('stage', 'new')->count(),
                'qualified' => Lead::where('stage', 'qualified')->count(),
                'proposal' => Lead::where('stage', 'proposal')->count(),
            ],
            'agents' => [
                'active' => Agent::where('status', 'active')->count(),
                'total' => Agent::count(),
                'running' => AgentRun::where('status', 'running')->count(),
                'pending_approvals' => ApprovalRequest::where('status', 'pending')->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
