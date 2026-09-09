<?php

namespace App\Mcp\Tools;

use App\Models\InteractionRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListInteractionRequestsTool extends Tool
{
    protected string $name = 'list-interaction-requests';

    protected string $title = 'List Interaction Requests';

    protected string $description = 'List pending interaction requests from agents that need human response.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status', 'pending');
        $runId = $request->get('run_id');
        $limit = min($request->get('limit', 20), 50);

        $query = InteractionRequest::with(['agentRun.agent'])
            ->orderBy('created_at', 'desc');

        if ($runId) {
            $query->where('agent_run_id', $runId);
        }

        switch ($status) {
            case 'pending':
                $query->pending();
                break;
            case 'expired':
                $query->expired();
                break;
            case 'responded':
                $query->whereNotNull('responded_at');
                break;
            case 'all':
                // No filter
                break;
            default:
                $query->pending();
        }

        $interactions = $query->limit($limit)->get();

        $data = $interactions->map(function ($interaction) {
            return [
                'id' => $interaction->id,
                'run_id' => $interaction->agent_run_id,
                'agent_name' => $interaction->agentRun?->agent?->name ?? 'Unknown',
                'question_type' => $interaction->question_type,
                'question' => $interaction->question,
                'options' => $interaction->options,
                'context' => $interaction->context,
                'is_pending' => $interaction->isPending(),
                'is_expired' => $interaction->isExpired(),
                'is_responded' => $interaction->isResponded(),
                'remaining_seconds' => $interaction->remaining_time,
                'expires_at' => $interaction->expires_at?->toIso8601String(),
                'responded_at' => $interaction->responded_at?->toIso8601String(),
                'responded_via' => $interaction->responded_via,
                'created_at' => $interaction->created_at?->toIso8601String(),
            ];
        });

        return Response::structured([
            'interactions' => $data->toArray(),
            'count' => $data->count(),
            'has_pending' => $data->contains('is_pending', true),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['pending', 'expired', 'responded', 'all'])
                ->description('Filter by status. Defaults to "pending".'),
            'run_id' => $schema->integer()
                ->description('Filter by specific agent run ID'),
            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 20, max: 50)'),
        ];
    }
}
