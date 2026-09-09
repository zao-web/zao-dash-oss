<?php

namespace App\Mcp\Tools;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approval\ApprovalDecisionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DecideApprovalRequestTool extends Tool
{
    protected string $name = 'decide-approval-request';

    protected string $title = 'Decide Approval Request';

    protected string $description = 'Approve or reject an approval request from Slack or the dashboard control plane.';

    public function __construct(
        protected ApprovalDecisionService $decisionService
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'approval_id' => 'required|integer|exists:approval_requests,id',
            'decision' => 'required|string|in:approve,reject',
            'note' => 'nullable|string',
        ]);

        $approval = ApprovalRequest::query()
            ->with(['agentRun.agent', 'decidedBy:id,name'])
            ->findOrFail($request->get('approval_id'));

        if ($approval->status !== 'pending') {
            return Response::structured([
                'success' => false,
                'message' => "Approval request #{$approval->id} has already been processed.",
                'approval' => [
                    'id' => $approval->id,
                    'status' => $approval->status,
                    'decision_note' => $approval->decision_note,
                    'decided_at' => $approval->decided_at?->toIso8601String(),
                    'decided_by' => $approval->decidedBy?->name,
                ],
            ]);
        }

        $actor = $this->resolveActingUser();
        $decision = $request->get('decision');
        $note = $request->get('note');

        $approval = $decision === 'approve'
            ? $this->decisionService->approve($approval, $actor, $note)
            : $this->decisionService->reject($approval, $actor, $note);

        return Response::structured([
            'success' => true,
            'message' => "Approval request #{$approval->id} {$approval->status}.",
            'approval' => [
                'id' => $approval->id,
                'status' => $approval->status,
                'risk_level' => $approval->risk_level,
                'action_type' => $approval->action_type,
                'description' => $approval->description,
                'decision_note' => $approval->decision_note,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'decided_by' => $approval->decidedBy?->name,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'approval_id' => $schema->integer()
                ->required()
                ->description('Approval request ID'),
            'decision' => $schema->string()
                ->required()
                ->enum(['approve', 'reject'])
                ->description('Whether to approve or reject the request'),
            'note' => $schema->string()
                ->description('Optional comment or reason for the decision'),
        ];
    }

    protected function resolveActingUser(): ?User
    {
        return Auth::user()
            ?? User::query()->whereIn('role', ['owner', 'admin'])->first()
            ?? User::query()->first();
    }
}
