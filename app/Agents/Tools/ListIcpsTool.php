<?php

namespace App\Agents\Tools;

use App\Models\IdealCustomerProfile;

class ListIcpsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'List ICPs';
    }

    public function description(): string
    {
        return 'List all Ideal Customer Profiles. Returns ICP details including industries, company sizes, tech stack, and scoring weights.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'active_only' => [
                    'type' => 'boolean',
                    'description' => 'Only return active ICPs (default: true)',
                ],
                'include_stats' => [
                    'type' => 'boolean',
                    'description' => 'Include prospect statistics for each ICP',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'active_only' => 'nullable|boolean',
            'include_stats' => 'nullable|boolean',
        ];
    }

    public function execute(array $params): array
    {
        $query = IdealCustomerProfile::query();

        if ($params['active_only'] ?? true) {
            $query->active();
        }

        $icps = $query->orderBy('name')->get();

        if ($icps->isEmpty()) {
            return [
                'success' => true,
                'count' => 0,
                'icps' => [],
                'message' => 'No ICPs found. Create one using create_icp tool.',
            ];
        }

        $includeStats = $params['include_stats'] ?? false;

        $result = $icps->map(function ($icp) use ($includeStats) {
            $data = [
                'id' => $icp->id,
                'name' => $icp->name,
                'slug' => $icp->slug,
                'description' => $icp->description,
                'industries' => $icp->industries ?? [],
                'company_sizes' => $icp->company_sizes ?? [],
                'tech_stack' => $icp->tech_stack ?? [],
                'buying_signals' => $icp->buying_signals ?? [],
                'pain_points' => $icp->pain_points ?? [],
                'avg_deal_value' => $icp->avg_deal_value,
                'is_active' => $icp->is_active,
                'weights' => [
                    'industry' => $icp->weight_industry,
                    'size' => $icp->weight_size,
                    'tech' => $icp->weight_tech,
                    'signals' => $icp->weight_signals,
                ],
            ];

            if ($includeStats) {
                $data['stats'] = $icp->prospect_stats;
            }

            return $data;
        });

        return [
            'success' => true,
            'count' => $icps->count(),
            'icps' => $result->toArray(),
        ];
    }
}
