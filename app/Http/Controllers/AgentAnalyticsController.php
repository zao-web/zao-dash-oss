<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\Agents\AgentAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentAnalyticsController extends Controller
{
    public function __construct(
        protected AgentAnalyticsService $analytics
    ) {}

    /**
     * Main analytics dashboard.
     */
    public function index(Request $request)
    {
        $days = $request->input('days', 30);

        return Inertia::render('Agents/Analytics', [
            'analytics' => $this->analytics->getDashboardAnalytics($days),
            'days' => $days,
            'agents' => Agent::select('id', 'name', 'slug', 'status')->get(),
        ]);
    }

    /**
     * API endpoint for analytics data.
     */
    public function data(Request $request)
    {
        $days = $request->input('days', 30);

        return response()->json(
            $this->analytics->getDashboardAnalytics($days)
        );
    }

    /**
     * Analytics for a specific agent.
     */
    public function agent(Agent $agent, Request $request)
    {
        $days = $request->input('days', 30);

        return Inertia::render('Agents/AgentAnalytics', [
            'analytics' => $this->analytics->getAgentAnalytics($agent, $days),
            'days' => $days,
        ]);
    }

    /**
     * API endpoint for agent-specific data.
     */
    public function agentData(Agent $agent, Request $request)
    {
        $days = $request->input('days', 30);

        return response()->json(
            $this->analytics->getAgentAnalytics($agent, $days)
        );
    }

    /**
     * Export analytics as CSV.
     */
    public function export(Request $request)
    {
        $days = $request->input('days', 30);
        $analytics = $this->analytics->getDashboardAnalytics($days);

        $csv = "Agent,Total Runs,Successful,Failed,Success Rate,Total Cost,Avg Cost\n";

        foreach ($analytics['by_agent'] as $agent) {
            $csv .= sprintf(
                "%s,%d,%d,%d,%.1f%%,$%.4f,$%.4f\n",
                $agent['name'],
                $agent['total_runs'],
                $agent['successful'],
                $agent['failed'],
                $agent['success_rate'],
                $agent['total_cost'],
                $agent['avg_cost']
            );
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="agent-analytics.csv"');
    }
}
