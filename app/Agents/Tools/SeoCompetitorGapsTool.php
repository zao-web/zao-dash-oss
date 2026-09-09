<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoCompetitorGapsTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-competitor-gaps';
    }

    public function name(): string
    {
        return 'SEO Competitor Gaps';
    }

    public function description(): string
    {
        return 'Find keyword gaps vs competitors. Analyzes competitor websites to identify keywords they rank for that we should target. Returns content opportunities and priority keywords.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'competitor_urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'URLs of competitor websites to analyze',
                ],
                'our_keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Keywords we currently focus on (to find gaps)',
                ],
            ],
            'required' => ['competitor_urls'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->findCompetitorGaps(
            competitorUrls: $params['competitor_urls'],
            ourKeywords: $params['our_keywords'] ?? []
        );

        return [
            'success' => true,
            'data' => $result,
        ];
    }
}
