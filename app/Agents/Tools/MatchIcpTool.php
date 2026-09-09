<?php

namespace App\Agents\Tools;

use App\Models\IdealCustomerProfile;
use App\Models\Prospect;

/**
 * Score a prospect against ICP criteria.
 */
class MatchIcpTool extends BaseTool
{
    public function category(): string
    {
        return 'analysis';
    }

    public function name(): string
    {
        return 'Match ICP';
    }

    public function description(): string
    {
        return 'Score a prospect against an Ideal Customer Profile to determine fit. Returns detailed breakdown of matching criteria.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prospect_id' => [
                    'type' => 'integer',
                    'description' => 'ID of existing prospect to score',
                ],
                'icp_id' => [
                    'type' => 'integer',
                    'description' => 'ID of ICP to match against',
                ],
                'prospect_data' => [
                    'type' => 'object',
                    'description' => 'Raw prospect data to score (if prospect_id not provided)',
                    'properties' => [
                        'industry' => ['type' => 'string'],
                        'company_size' => ['type' => 'string'],
                        'tech_stack' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'signals' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'required' => ['icp_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'prospect_id' => 'nullable|integer|exists:prospects,id',
            'icp_id' => 'required|integer|exists:ideal_customer_profiles,id',
            'prospect_data' => 'nullable|array',
            'prospect_data.industry' => 'nullable|string',
            'prospect_data.company_size' => 'nullable|string',
            'prospect_data.tech_stack' => 'nullable|array',
            'prospect_data.signals' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $icp = IdealCustomerProfile::find($params['icp_id']);

        if (! $icp) {
            return [
                'success' => false,
                'error' => 'icp_not_found',
                'message' => 'Ideal Customer Profile not found',
            ];
        }

        // Get prospect data
        $prospectData = $params['prospect_data'] ?? [];

        if (! empty($params['prospect_id'])) {
            $prospect = Prospect::find($params['prospect_id']);
            if (! $prospect) {
                return [
                    'success' => false,
                    'error' => 'prospect_not_found',
                    'message' => 'Prospect not found',
                ];
            }
            $prospectData = [
                'industry' => $prospect->industry,
                'company_size' => $prospect->company_size,
                'tech_stack' => $prospect->tech_stack ?? [],
                'signals' => $prospect->signals ?? [],
            ];
        }

        // Score the prospect
        $result = $icp->scoreProspect($prospectData);

        // Update prospect if provided
        if (! empty($params['prospect_id'])) {
            $prospect->update([
                'icp_id' => $icp->id,
                'icp_score' => $result['total_score'],
                'score_breakdown' => $result['breakdown'],
                'status' => $result['is_qualified'] ? Prospect::STATUS_QUALIFIED : $prospect->status,
            ]);
        }

        return [
            'success' => true,
            'icp' => [
                'id' => $icp->id,
                'name' => $icp->name,
                'criteria' => [
                    'industries' => $icp->industries,
                    'company_sizes' => $icp->company_sizes,
                    'tech_stack' => $icp->tech_stack,
                    'buying_signals' => $icp->buying_signals,
                ],
            ],
            'score' => $result['total_score'],
            'is_qualified' => $result['is_qualified'],
            'breakdown' => $result['breakdown'],
            'recommendation' => $this->getRecommendation($result),
        ];
    }

    protected function getRecommendation(array $result): string
    {
        $score = $result['total_score'];

        if ($score >= 80) {
            return 'Excellent fit. High priority prospect - recommend immediate outreach.';
        }
        if ($score >= 60) {
            return 'Good fit. Qualified prospect - add to outreach campaign.';
        }
        if ($score >= 40) {
            return 'Potential fit. May need more research or nurturing before outreach.';
        }

        return 'Low fit. Consider different ICP or mark as unqualified.';
    }
}
