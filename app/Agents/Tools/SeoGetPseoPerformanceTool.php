<?php

namespace App\Agents\Tools;

use App\Models\User;
use App\Services\Google\SearchConsoleService;

class SeoGetPseoPerformanceTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-get-pseo-performance';
    }

    public function name(): string
    {
        return 'Get PSEO Performance Summary';
    }

    public function description(): string
    {
        return 'Get performance summary for all programmatic SEO pages. Returns total pages, impressions, clicks, CTR, and top performing pages. Use for PSEO ROI tracking.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'site_url' => [
                    'type' => 'string',
                    'description' => 'Site URL as registered in Search Console',
                ],
            ],
            'required' => ['site_url'],
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

            $performance = $service->getPseoPerformance(
                $user,
                $params['site_url']
            );

            return [
                'success' => true,
                'data' => $performance,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
