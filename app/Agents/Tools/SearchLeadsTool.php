<?php

namespace App\Agents\Tools;

use App\Models\Lead;

/**
 * Search for leads in the pipeline.
 */
class SearchLeadsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Leads';
    }

    public function description(): string
    {
        return 'Search for leads by name, stage, or other criteria. Returns matching leads with deal values and contact history.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to find in company name or contact info',
                ],
                'stage' => [
                    'type' => 'string',
                    'enum' => ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'],
                    'description' => 'Filter by pipeline stage',
                ],
                'stages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Filter by multiple stages',
                ],
                'min_deal_value' => [
                    'type' => 'number',
                    'description' => 'Minimum deal value in USD',
                ],
                'days_since_contact' => [
                    'type' => 'integer',
                    'description' => 'Only leads not contacted in this many days',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['deal_value', 'last_contacted', 'created_at'],
                    'description' => 'Sort results by field',
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
            'query' => 'nullable|string|max:100',
            'stage' => 'nullable|in:new,qualified,proposal,negotiation,won,lost',
            'stages' => 'nullable|array',
            'min_deal_value' => 'nullable|numeric|min:0',
            'days_since_contact' => 'nullable|integer|min:1',
            'sort_by' => 'nullable|in:deal_value,last_contacted,created_at',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function execute(array $params): array
    {
        $query = Lead::query();

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if (! empty($params['stage'])) {
            $query->where('stage', $params['stage']);
        }

        if (! empty($params['stages'])) {
            $query->whereIn('stage', $params['stages']);
        }

        if (! empty($params['min_deal_value'])) {
            $query->where('deal_value', '>=', $params['min_deal_value']);
        }

        if (! empty($params['days_since_contact'])) {
            $cutoff = now()->subDays($params['days_since_contact']);
            $query->where(function ($q) use ($cutoff) {
                $q->whereNull('last_contacted_at')
                    ->orWhere('last_contacted_at', '<', $cutoff);
            });
        }

        // Sorting
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDir = $sortBy === 'deal_value' ? 'desc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = $params['limit'] ?? 10;
        $leads = $query->limit($limit)->get();

        return [
            'count' => $leads->count(),
            'leads' => $leads->map(fn ($l) => [
                'id' => $l->id,
                'company_name' => $l->company_name,
                'contact_name' => $l->contact_name,
                'contact_email' => $l->contact_email,
                'stage' => $l->stage,
                'deal_value' => $l->deal_value,
                'source' => $l->source,
                'last_contacted_at' => $l->last_contacted_at?->toDateTimeString(),
                'days_since_contact' => $l->last_contacted_at
                    ? now()->diffInDays($l->last_contacted_at)
                    : null,
                'created_at' => $l->created_at->toDateTimeString(),
                'notes' => $l->notes,
            ])->toArray(),
        ];
    }
}
