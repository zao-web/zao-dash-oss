<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoKeywordResearchTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-keyword-research';
    }

    public function name(): string
    {
        return 'SEO Keyword Research';
    }

    public function description(): string
    {
        return 'Research SEO keywords for a topic. Returns keywords with search volume estimates, difficulty scores, and search intent classification. Use for content planning and programmatic SEO.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'Main topic to research keywords for (e.g., "WordPress development", "Laravel API")',
                ],
                'seed_keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional seed keywords to expand from',
                ],
                'intent_filter' => [
                    'type' => 'string',
                    'enum' => ['informational', 'commercial', 'transactional', 'navigational'],
                    'description' => 'Filter results by search intent type',
                ],
            ],
            'required' => ['topic'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->keywordResearch(
            topic: $params['topic'],
            seedKeywords: $params['seed_keywords'] ?? [],
            intentFilter: $params['intent_filter'] ?? null
        );

        return [
            'success' => true,
            'data' => $result,
        ];
    }
}
