<?php

namespace App\Mcp\Tools;

use App\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListAgentsTool extends Tool
{
    protected string $name = 'list-agents';

    protected string $title = 'List Agents';

    protected string $description = 'List all AI agents configured in the system with their status and run counts.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');

        $query = Agent::withCount(['runs', 'runs as completed_runs_count' => fn ($q) => $q->where('status', 'completed')]);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $agents = $query->get()->map(fn ($agent) => [
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'description' => $agent->description,
            'status' => $agent->status,
            'model' => $agent->model,
            'requires_approval' => $agent->requires_approval,
            'runs_count' => $agent->runs_count,
            'completed_runs_count' => $agent->completed_runs_count,
            'success_rate' => $agent->runs_count > 0 ? round(($agent->completed_runs_count / $agent->runs_count) * 100) : 0,
            'total_cost' => round((float) $agent->runs()->sum('cost_usd'), 4),
            'schedule' => $agent->schedule,
        ]);

        return Response::structured([
            'agents' => $agents,
            'total' => $agents->count(),
            'active_count' => $agents->where('status', 'active')->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['active', 'inactive', 'all'])
                ->description('Filter by agent status'),
        ];
    }
}
