<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoOptimizeContentTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-optimize-content';
    }

    public function name(): string
    {
        return 'Optimize Content for SEO';
    }

    public function description(): string
    {
        return 'Analyze and optimize existing content for a target keyword. Returns SEO score, issues found, optimized version, and improvement recommendations.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'current_content' => [
                    'type' => 'string',
                    'description' => 'The existing content to optimize (HTML or plain text)',
                ],
                'target_keyword' => [
                    'type' => 'string',
                    'description' => 'The keyword to optimize the content for',
                ],
            ],
            'required' => ['current_content', 'target_keyword'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->optimizeContent(
            currentContent: $params['current_content'],
            targetKeyword: $params['target_keyword']
        );

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error'],
            ];
        }

        return [
            'success' => true,
            'data' => $result,
        ];
    }
}
