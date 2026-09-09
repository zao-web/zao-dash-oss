<?php

namespace App\Mcp\Tools;

use App\Models\ApprovalRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListApprovalRequestsTool extends Tool
{
    protected string $name = 'list-approval-requests';

    protected string $title = 'List Approval Requests';

    protected string $description = 'List approval requests that need review or have already been processed.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status', 'pending');
        $riskLevel = $request->get('risk_level');
        $limit = min((int) $request->get('limit', 10), 25);

        $query = ApprovalRequest::query()
            ->with(['agentRun.agent', 'decidedBy:id,name'])
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($riskLevel) {
            $query->where('risk_level', $riskLevel);
        }

        $approvals = $query->limit($limit)->get();

        return Response::structured([
            'approvals' => $approvals->map(fn (ApprovalRequest $approval) => [
                'id' => $approval->id,
                'status' => $approval->status,
                'risk_level' => $approval->risk_level,
                'action_type' => $approval->action_type,
                'description' => $approval->description,
                'decision_note' => $approval->decision_note,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'decided_by' => $approval->decidedBy?->name,
                'expires_at' => $approval->expires_at?->toIso8601String(),
                'created_at' => $approval->created_at?->toIso8601String(),
                'agent' => $approval->agentRun?->agent ? [
                    'id' => $approval->agentRun->agent->id,
                    'name' => $approval->agentRun->agent->name,
                    'slug' => $approval->agentRun->agent->slug,
                ] : null,
                'run' => $approval->agentRun ? [
                    'id' => $approval->agentRun->id,
                    'status' => $approval->agentRun->status,
                    'task' => $approval->agentRun->task,
                ] : null,
            ])->values()->all(),
            'count' => $approvals->count(),
            'has_pending' => $approvals->contains(fn (ApprovalRequest $approval) => $approval->status === 'pending'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['pending', 'approved', 'rejected', 'expired', 'all'])
                ->description('Approval status filter. Defaults to pending.'),
            'risk_level' => $schema->string()
                ->enum(['low', 'medium', 'high', 'critical'])
                ->description('Optional risk level filter.'),
            'limit' => $schema->integer()
                ->description('Maximum number of approvals to return. Defaults to 10, max 25.'),
        ];
    }
}
