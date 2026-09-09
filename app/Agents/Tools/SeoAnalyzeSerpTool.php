<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoAnalyzeSerpTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-analyze-serp';
    }

    public function name(): string
    {
        return 'Analyze SERP';
    }

    public function description(): string
    {
        return 'Analyze search engine results page for a keyword. Returns top-ranking content patterns, SERP features, content gaps, and ranking opportunities. Use before generating content to understand what ranks.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'The exact keyword/phrase to analyze SERP for',
                ],
            ],
            'required' => ['keyword'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->analyzeSerpForKeyword($params['keyword']);

        return [
            'success' => true,
            'data' => $result,
        ];
    }
}
