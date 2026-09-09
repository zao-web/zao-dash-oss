<?php

namespace App\Agents\Tools;

use App\Services\QuarterlyPatternAnalysisService;

class GetQuarterlyPatternsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function id(): string
    {
        return 'get-quarterly-patterns';
    }

    public function name(): string
    {
        return 'Get Quarterly Patterns';
    }

    public function description(): string
    {
        return 'Analyze quarterly work patterns to identify industry verticals, service focus, and technology trends. Use for content ideation and SEO targeting based on actual work completed.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quarter' => [
                    'type' => 'string',
                    'description' => 'Quarter to analyze (e.g., "Q4 2024"). Defaults to current quarter.',
                ],
            ],
            'required' => [],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        try {
            $service = app(QuarterlyPatternAnalysisService::class);
            $analysis = $service->analyze();

            return [
                'success' => true,
                'data' => [
                    'period' => $analysis['period'] ?? [],
                    'industry_patterns' => $analysis['industry_patterns'] ?? [],
                    'service_patterns' => $analysis['service_patterns'] ?? [],
                    'technology_patterns' => $analysis['technology_patterns'] ?? [],
                    'content_suggestions' => $analysis['content_suggestions'] ?? [],
                    'landing_page_suggestions' => $analysis['landing_page_suggestions'] ?? [],
                    'outreach_suggestions' => $analysis['outreach_suggestions'] ?? [],
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
