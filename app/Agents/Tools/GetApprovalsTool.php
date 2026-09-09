<?php

namespace App\Agents\Tools;

use App\Models\ApprovalRequest;

/**
 * Get pending approvals.
 */
class GetApprovalsTool extends BaseTool
{
    public function category(): string
    {
        return 'navigation';
    }

    public function name(): string
    {
        return 'Get Approvals';
    }

    public function description(): string
    {
        return 'List pending approval requests that need review.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'approved', 'rejected'],
                    'description' => 'Filter by status (default: pending)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 10)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'status' => 'nullable|in:pending,approved,rejected',
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }

    public function execute(array $params): array
    {
        $query = ApprovalRequest::with('agentRun.agent');

        $status = $params['status'] ?? 'pending';
        $query->where('status', $status);

        $limit = $params['limit'] ?? 10;
        $approvals = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => $approvals->count(),
            'approvals' => $approvals->map(fn ($a) => [
                'id' => $a->id,
                'action_type' => $a->action_type,
                'description' => $a->description,
                'status' => $a->status,
                'agent' => $a->agentRun?->agent?->name,
                'created_at' => $a->created_at->toDateTimeString(),
            ])->toArray(),
        ];
    }
}
