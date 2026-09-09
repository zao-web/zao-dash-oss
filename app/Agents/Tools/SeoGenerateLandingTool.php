<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoResearchService;

class SeoGenerateLandingTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-generate-landing';
    }

    public function name(): string
    {
        return 'Generate SEO Landing Page';
    }

    public function description(): string
    {
        return 'Generate SEO landing page. CRITICAL: Must NOT sound like AI - no em dashes, no "delve/leverage/unlock/seamlessly", write like a senior dev. Include clear conversion path. Requires approval to publish.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Target keyword for the landing page',
                ],
                'page_type' => [
                    'type' => 'string',
                    'enum' => ['service', 'industry', 'location', 'comparison', 'solution'],
                    'description' => 'Type of landing page to generate',
                ],
                'template_style' => [
                    'type' => 'string',
                    'enum' => ['professional', 'technical', 'friendly', 'enterprise'],
                    'description' => 'Tone and style for the content',
                ],
            ],
            'required' => ['keyword', 'page_type'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // Generation doesn't require approval, publishing does
    }

    public function execute(array $params): array
    {
        $service = app(SeoResearchService::class);

        $result = $service->generateLandingPage(
            keyword: $params['keyword'],
            pageType: $params['page_type'],
            templateStyle: $params['template_style'] ?? 'professional'
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
