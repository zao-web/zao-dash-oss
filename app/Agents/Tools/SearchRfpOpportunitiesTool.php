<?php

namespace App\Agents\Tools;

use App\Models\RfpOpportunity;

/**
 * Search and filter RFP opportunities in the pipeline.
 */
class SearchRfpOpportunitiesTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search RFP Opportunities';
    }

    public function description(): string
    {
        return 'Search for RFP opportunities by status, fit score, source type, or keyword. Returns opportunities with scoring and budget details.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to find in title, organization, or description',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['discovered', 'evaluating', 'pursuing', 'proposal_draft', 'proposal_review', 'submitted', 'won', 'lost', 'declined', 'expired'],
                    'description' => 'Filter by pipeline status',
                ],
                'min_fit_score' => [
                    'type' => 'integer',
                    'description' => 'Minimum fit score (0-100)',
                ],
                'source_type' => [
                    'type' => 'string',
                    'enum' => ['email_teaser', 'sam_gov', 'rfp_board', 'web_scrape', 'manual'],
                    'description' => 'Filter by discovery source type',
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
            'query' => 'nullable|string|max:200',
            'status' => 'nullable|in:discovered,evaluating,pursuing,proposal_draft,proposal_review,submitted,won,lost,declined,expired',
            'min_fit_score' => 'nullable|integer|min:0|max:100',
            'source_type' => 'nullable|in:email_teaser,sam_gov,rfp_board,web_scrape,manual',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $query = RfpOpportunity::query();

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('issuing_organization', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['min_fit_score'])) {
            $query->where('fit_score', '>=', $params['min_fit_score']);
        }

        if (! empty($params['source_type'])) {
            $query->where('source_type', $params['source_type']);
        }

        $query->orderByDesc('fit_score');

        $limit = $params['limit'] ?? 10;
        $opportunities = $query->limit($limit)->get();

        return [
            'count' => $opportunities->count(),
            'opportunities' => $opportunities->map(fn (RfpOpportunity $opp) => [
                'id' => $opp->id,
                'title' => $opp->title,
                'issuing_organization' => $opp->issuing_organization,
                'status' => $opp->status,
                'fit_score' => $opp->fit_score,
                'budget_range' => $opp->budgetRange(),
                'budget_min' => $opp->budget_min,
                'budget_max' => $opp->budget_max,
                'submission_deadline' => $opp->submission_deadline?->toDateString(),
                'source_type' => $opp->source_type,
                'priority' => $opp->priority,
                'tech_requirements' => $opp->tech_requirements,
                'created_at' => $opp->created_at->toDateTimeString(),
            ])->toArray(),
        ];
    }
}
