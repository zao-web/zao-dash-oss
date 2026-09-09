<?php

namespace App\Agents\Tools;

use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;

/**
 * Get system statistics.
 */
class GetStatsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Get Statistics';
    }

    public function description(): string
    {
        return 'Get current statistics about projects, tasks, clients, and agents. Useful for dashboard-style queries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: projects, tasks, clients, agents, approvals. Default: all.',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $include = $params['include'] ?? ['projects', 'tasks', 'clients', 'agents', 'approvals'];
        $stats = [];

        if (in_array('projects', $include)) {
            $stats['projects'] = [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
                'completed' => Project::where('status', 'completed')->count(),
                'total_budget' => Project::sum('budget'),
            ];
        }

        if (in_array('tasks', $include)) {
            $stats['tasks'] = [
                'total' => Task::count(),
                'pending' => Task::where('status', 'pending')->count(),
                'in_progress' => Task::where('status', 'in_progress')->count(),
                'review' => Task::where('status', 'review')->count(),
                'completed' => Task::where('status', 'completed')->count(),
                'overdue' => Task::where('status', '!=', 'completed')
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->count(),
                'completed_today' => Task::where('status', 'completed')
                    ->whereDate('updated_at', today())
                    ->count(),
            ];
        }

        if (in_array('clients', $include)) {
            $stats['clients'] = [
                'total' => Client::count(),
                'active' => Client::where('status', 'active')->count(),
                'avg_health_score' => round(Client::where('status', 'active')->avg('health_score') ?? 0, 1),
            ];
        }

        if (in_array('agents', $include)) {
            $stats['agents'] = [
                'total' => Agent::count(),
                'active' => Agent::where('status', 'active')->count(),
                'runs_today' => \App\Models\AgentRun::whereDate('created_at', today())->count(),
            ];
        }

        if (in_array('approvals', $include)) {
            $stats['approvals'] = [
                'pending' => ApprovalRequest::where('status', 'pending')->count(),
                'approved_today' => ApprovalRequest::where('status', 'approved')
                    ->whereDate('decided_at', today())
                    ->count(),
            ];
        }

        return $stats;
    }
}
