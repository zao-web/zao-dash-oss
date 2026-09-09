<?php

namespace App\Agents\Tools;

use App\Models\IdealCustomerProfile;

class CreateIcpTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Create ICP';
    }

    public function description(): string
    {
        return 'Create a new Ideal Customer Profile. Define target industries, company sizes, tech stack, and buying signals to score prospects against.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the ICP (e.g., "Enterprise SaaS", "E-commerce Startups")',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Description of this ideal customer type',
                ],
                'industries' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Target industries (e.g., ["SaaS", "E-commerce", "FinTech"])',
                ],
                'company_sizes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Target company sizes (e.g., ["11-50", "51-200", "201-500"])',
                ],
                'tech_stack' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Technologies that indicate fit (e.g., ["WordPress", "Shopify", "React"])',
                ],
                'buying_signals' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Signals indicating purchase readiness (e.g., ["recent_funding", "hiring_developers", "website_redesign"])',
                ],
                'pain_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Common problems this segment faces',
                ],
                'avg_deal_value' => [
                    'type' => 'number',
                    'description' => 'Expected average deal value in dollars',
                ],
                'weights' => [
                    'type' => 'object',
                    'description' => 'Scoring weights (must sum to 100)',
                    'properties' => [
                        'industry' => ['type' => 'integer', 'description' => 'Weight for industry match (default: 25)'],
                        'size' => ['type' => 'integer', 'description' => 'Weight for company size match (default: 20)'],
                        'tech' => ['type' => 'integer', 'description' => 'Weight for tech stack match (default: 30)'],
                        'signals' => ['type' => 'integer', 'description' => 'Weight for buying signals match (default: 25)'],
                    ],
                ],
            ],
            'required' => ['name', 'industries'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'industries' => 'required|array|min:1',
            'industries.*' => 'string',
            'company_sizes' => 'nullable|array',
            'company_sizes.*' => 'string',
            'tech_stack' => 'nullable|array',
            'tech_stack.*' => 'string',
            'buying_signals' => 'nullable|array',
            'buying_signals.*' => 'string',
            'pain_points' => 'nullable|array',
            'pain_points.*' => 'string',
            'avg_deal_value' => 'nullable|numeric|min:0',
            'weights' => 'nullable|array',
            'weights.industry' => 'nullable|integer|min:0|max:100',
            'weights.size' => 'nullable|integer|min:0|max:100',
            'weights.tech' => 'nullable|integer|min:0|max:100',
            'weights.signals' => 'nullable|integer|min:0|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $weights = $params['weights'] ?? [];

        $icp = IdealCustomerProfile::create([
            'name' => $params['name'],
            'description' => $params['description'] ?? null,
            'industries' => $params['industries'],
            'company_sizes' => $params['company_sizes'] ?? [],
            'tech_stack' => $params['tech_stack'] ?? [],
            'buying_signals' => $params['buying_signals'] ?? [],
            'pain_points' => $params['pain_points'] ?? [],
            'avg_deal_value' => $params['avg_deal_value'] ?? 0,
            'weight_industry' => $weights['industry'] ?? 25,
            'weight_size' => $weights['size'] ?? 20,
            'weight_tech' => $weights['tech'] ?? 30,
            'weight_signals' => $weights['signals'] ?? 25,
            'is_active' => true,
        ]);

        return [
            'success' => true,
            'icp_id' => $icp->id,
            'name' => $icp->name,
            'slug' => $icp->slug,
            'message' => "Created ICP: {$icp->name}",
        ];
    }
}
