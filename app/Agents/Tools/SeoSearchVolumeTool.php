<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoSearchVolumeTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-search-volume';
    }

    public function name(): string
    {
        return 'SEO Search Volume';
    }

    public function description(): string
    {
        return 'Get estimated search volume and metrics for a list of keywords. Returns monthly volume estimates, difficulty scores, search intent, and trend direction.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of keywords to get volume data for',
                ],
            ],
            'required' => ['keywords'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->getSearchVolume($params['keywords']);

        return [
            'success' => true,
            'data' => $result,
        ];
    }
}
