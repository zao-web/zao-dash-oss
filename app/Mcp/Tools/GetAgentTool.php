<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetAgentTool extends Tool
{
    protected string $name = 'get-agent';

    protected string $title = 'Get Agent Details';

    protected string $description = 'Get detailed information about a specific agent including recent runs and statistics.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $agent = $request->get('id')
            ? Agent::findOrFail($request->get('id'))
            : Agent::where('slug', $request->get('slug'))->firstOrFail();

        $runs = $agent->runs()->orderBy('created_at', 'desc')->limit(10)->get();
        $completedRuns = $agent->runs()->where('status', 'completed')->count();
        $failedRuns = $agent->runs()->where('status', 'failed')->count();
        $totalRuns = $agent->runs()->count();

        return Response::structured([
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'description' => $agent->description,
            'status' => $agent->status,
            'model' => $agent->model,
            'requires_approval' => $agent->requires_approval,
            'max_budget_usd' => $agent->max_budget_usd,
            'allowed_tools' => $agent->allowed_tools,
            'schedule' => $agent->schedule,
            'trigger_config' => $agent->trigger_config,
            'circuit_broken_at' => $agent->circuit_broken_at?->toIso8601String(),
            'stats' => [
                'total_runs' => $totalRuns,
                'completed_runs' => $completedRuns,
                'failed_runs' => $failedRuns,
                'success_rate' => $totalRuns > 0 ? round(($completedRuns / $totalRuns) * 100) : 0,
                'total_cost' => round((float) $agent->runs()->sum('cost_usd'), 4),
                'avg_cost' => round((float) $agent->runs()->avg('cost_usd'), 4),
                'runs_today' => $agent->runs()->whereDate('created_at', today())->count(),
            ],
            'recent_runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'task' => $r->task,
                'cost_usd' => $r->cost_usd,
                'created_at' => $r->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Agent ID'),
            'slug' => $schema->string()->description('Agent slug (alternative to ID)'),
        ];
    }
}
