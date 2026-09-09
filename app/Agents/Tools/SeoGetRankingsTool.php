<?php

namespace App\Agents\Tools;

use App\Models\User;
use App\Services\Google\SearchConsoleService;

class SeoGetRankingsTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-get-rankings';
    }

    public function name(): string
    {
        return 'Get SEO Rankings';
    }

    public function description(): string
    {
        return 'Get keyword rankings and performance data from Google Search Console. Returns position, impressions, clicks, and CTR for specified keywords.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Keywords to check rankings for',
                ],
                'site_url' => [
                    'type' => 'string',
                    'description' => 'Site URL as registered in Search Console (e.g., sc-domain:example.com or https://example.com/)',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Number of days to analyze (default: 28)',
                ],
            ],
            'required' => ['keywords', 'site_url'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        try {
            $service = app(SearchConsoleService::class);
            $user = User::first(); // Admin user for service-level access

            $rankings = $service->getKeywordRankings(
                $user,
                $params['site_url'],
                $params['keywords'],
                $params['days'] ?? 28
            );

            return [
                'success' => true,
                'data' => [
                    'site_url' => $params['site_url'],
                    'period_days' => $params['days'] ?? 28,
                    'rankings' => $rankings,
                    'summary' => [
                        'keywords_tracked' => count($rankings),
                        'total_impressions' => array_sum(array_column($rankings, 'total_impressions')),
                        'total_clicks' => array_sum(array_column($rankings, 'total_clicks')),
                    ],
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
