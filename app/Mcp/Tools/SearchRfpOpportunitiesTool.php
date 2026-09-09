<?php

namespace App\Mcp\Tools;

use App\Models\RfpOpportunity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchRfpOpportunitiesTool extends Tool
{
    protected string $name = 'search-rfp-opportunities';

    protected string $title = 'Search RFP Opportunities';

    protected string $description = 'Search and list RFP opportunities with optional filtering by status, priority, or keyword.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $priority = $request->get('priority');
        $keyword = $request->get('keyword');
        $limit = $request->get('limit', 50);

        $query = RfpOpportunity::with('assignee')
            ->orderBy('status')
            ->orderBy('fit_score', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($priority) {
            $query->where('priority', $priority);
        }

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                    ->orWhere('issuing_organization', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $opportunities = $query->limit($limit)->get()->map(fn (RfpOpportunity $o) => [
            'id' => $o->id,
            'title' => $o->title,
            'issuing_organization' => $o->issuing_organization,
            'status' => $o->status,
            'priority' => $o->priority,
            'fit_score' => $o->fit_score,
            'budget_range' => $o->budgetRange(),
            'submission_deadline' => $o->submission_deadline?->format('Y-m-d'),
            'is_expired' => $o->isExpired(),
            'assignee' => $o->assignee?->name,
            'source_type' => $o->source_type,
        ]);

        $activeStatuses = ['discovered', 'evaluating', 'qualified', 'pursuing', 'proposal_drafting', 'proposal_review', 'submitted'];

        return Response::structured([
            'opportunities' => $opportunities,
            'total' => $opportunities->count(),
            'active_count' => $opportunities->whereIn('status', $activeStatuses)->count(),
            'avg_fit_score' => round($opportunities->avg('fit_score') ?? 0),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['discovered', 'evaluating', 'qualified', 'pursuing', 'proposal_drafting', 'proposal_review', 'submitted', 'won', 'lost', 'all'])
                ->description('Filter by pipeline status'),
            'priority' => $schema->string()
                ->enum(['low', 'medium', 'high', 'critical'])
                ->description('Filter by priority level'),
            'keyword' => $schema->string()->description('Search by title, organization, or description'),
            'limit' => $schema->integer()->description('Maximum results to return (default: 50)'),
        ];
    }
}
