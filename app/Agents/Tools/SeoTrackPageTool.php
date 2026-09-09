<?php

namespace App\Agents\Tools;

use App\Models\User;
use App\Services\Google\SearchConsoleService;

class SeoTrackPageTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-track-page';
    }

    public function name(): string
    {
        return 'Track SEO Page Performance';
    }

    public function description(): string
    {
        return 'Track SEO KPIs for a specific page including impressions, clicks, position, and period-over-period changes. Use to monitor PSEO page performance.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_url' => [
                    'type' => 'string',
                    'description' => 'Full URL of the page to track',
                ],
                'site_url' => [
                    'type' => 'string',
                    'description' => 'Site URL as registered in Search Console',
                ],
            ],
            'required' => ['page_url', 'site_url'],
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
            $user = User::first();

            $kpis = $service->trackSeoKpis(
                $user,
                $params['site_url'],
                $params['page_url']
            );

            return [
                'success' => true,
                'data' => $kpis,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
