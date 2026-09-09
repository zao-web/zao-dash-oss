<?php

namespace App\Agents\Tools;

use App\Models\User;
use App\Services\Google\SearchConsoleService;

class SeoGetConversionsTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-get-conversions';
    }

    public function name(): string
    {
        return 'Get SEO Conversions';
    }

    public function description(): string
    {
        return 'Get conversion data for SEO pages from Google Analytics. Returns form submissions and other conversion events attributed to organic search traffic.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'property_id' => [
                    'type' => 'string',
                    'description' => 'Google Analytics 4 property ID (e.g., 123456789)',
                ],
                'conversion_event' => [
                    'type' => 'string',
                    'description' => 'Event name to track as conversion (default: form_submit)',
                ],
            ],
            'required' => ['property_id'],
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

            $conversions = $service->getSeoConversions(
                $user,
                $params['property_id'],
                $params['conversion_event'] ?? 'form_submit'
            );

            $totalConversions = array_sum(array_column($conversions, 'page_views'));

            return [
                'success' => true,
                'data' => [
                    'property_id' => $params['property_id'],
                    'conversion_event' => $params['conversion_event'] ?? 'form_submit',
                    'total_conversions' => $totalConversions,
                    'by_page' => $conversions,
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
