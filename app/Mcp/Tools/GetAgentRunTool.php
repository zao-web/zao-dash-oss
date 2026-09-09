<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetAgentRunTool extends Tool
{
    protected string $name = 'get-agent-run';

    protected string $title = 'Get Agent Run';

    protected string $description = 'Get the status and output of a specific agent run.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'run_id' => 'required|exists:agent_runs,id',
        ]);

        $run = AgentRun::with('agent')->findOrFail($request->get('run_id'));

        return Response::structured([
            'id' => $run->id,
            'agent' => [
                'id' => $run->agent->id,
                'name' => $run->agent->name,
                'slug' => $run->agent->slug,
            ],
            'status' => $run->status,
            'task' => $run->task,
            'context' => $run->context,
            'output' => $run->output,
            'error_message' => $run->error_message,
            'cost_usd' => $run->cost_usd,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'duration_ms' => $run->duration_ms,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->integer()->required()->description('Agent run ID'),
        ];
    }
}
