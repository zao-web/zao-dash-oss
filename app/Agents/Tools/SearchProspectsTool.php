<?php

namespace App\Agents\Tools;

use App\Models\IdealCustomerProfile;
use App\Models\Prospect;

/**
 * Search for prospects and potential leads.
 */
class SearchProspectsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Prospects';
    }

    public function description(): string
    {
        return 'Search for prospects by company name, industry, ICP score, or status. Returns prospects with their qualification scores.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to find in company or contact name',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['new', 'researching', 'qualified', 'unqualified', 'converted'],
                    'description' => 'Filter by prospect status',
                ],
                'icp_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by Ideal Customer Profile ID',
                ],
                'min_icp_score' => [
                    'type' => 'integer',
                    'description' => 'Minimum ICP qualification score (0-100)',
                ],
                'industry' => [
                    'type' => 'string',
                    'description' => 'Filter by industry',
                ],
                'not_contacted' => [
                    'type' => 'boolean',
                    'description' => 'Only prospects not yet contacted',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['icp_score', 'created_at', 'company_name'],
                    'description' => 'Sort results by field',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 20)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'nullable|string|max:100',
            'status' => 'nullable|in:new,researching,qualified,unqualified,converted',
            'icp_id' => 'nullable|integer|exists:ideal_customer_profiles,id',
            'min_icp_score' => 'nullable|integer|min:0|max:100',
            'industry' => 'nullable|string',
            'not_contacted' => 'nullable|boolean',
            'sort_by' => 'nullable|in:icp_score,created_at,company_name',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $query = Prospect::with('icp');

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['icp_id'])) {
            $query->where('icp_id', $params['icp_id']);
        }

        if (! empty($params['min_icp_score'])) {
            $query->where('icp_score', '>=', $params['min_icp_score']);
        }

        if (! empty($params['industry'])) {
            $query->where('industry', $params['industry']);
        }

        if (! empty($params['not_contacted'])) {
            $query->whereDoesntHave('outreachMessages', function ($q) {
                $q->whereNotNull('sent_at');
            });
        }

        // Sorting
        $sortBy = $params['sort_by'] ?? 'icp_score';
        $sortDir = $sortBy === 'company_name' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $limit = $params['limit'] ?? 20;
        $prospects = $query->limit($limit)->get();

        // Get available ICPs for context
        $icps = IdealCustomerProfile::active()->get(['id', 'name', 'slug']);

        return [
            'count' => $prospects->count(),
            'prospects' => $prospects->map(fn ($p) => [
                'id' => $p->id,
                'company_name' => $p->company_name,
                'company_website' => $p->company_website,
                'industry' => $p->industry,
                'company_size' => $p->company_size,
                'contact_name' => $p->contact_name,
                'contact_title' => $p->contact_title,
                'contact_email' => $p->contact_email,
                'icp_id' => $p->icp_id,
                'icp_name' => $p->icp?->name,
                'icp_score' => $p->icp_score,
                'qualification_status' => $p->qualification_status,
                'status' => $p->status,
                'source' => $p->source,
                'created_at' => $p->created_at->toDateTimeString(),
            ])->toArray(),
            'available_icps' => $icps->toArray(),
        ];
    }
}
