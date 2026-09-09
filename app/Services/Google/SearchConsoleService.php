<?php

namespace App\Services\Google;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class SearchConsoleService
{
    private const SEARCH_CONSOLE_API = 'https://searchconsole.googleapis.com/webmasters/v3';

    private const ANALYTICS_API = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(
        private GoogleOAuthService $oauth
    ) {}

    /**
     * Get sites verified in Search Console
     */
    public function getSites(User $user): array
    {
        $token = $this->oauth->getValidAccessToken($user);
        if (! $token) {
            throw new \Exception('Google account not connected');
        }

        $response = Http::withToken($token)
            ->get(self::SEARCH_CONSOLE_API.'/sites');

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch sites: '.$response->body());
        }

        return $response->json()['siteEntry'] ?? [];
    }

    /**
     * Get search analytics (keyword rankings, impressions, clicks)
     */
    public function getSearchAnalytics(
        User $user,
        string $siteUrl,
        array $options = []
    ): array {
        $token = $this->oauth->getValidAccessToken($user);
        if (! $token) {
            throw new \Exception('Google account not connected');
        }

        $startDate = $options['start_date'] ?? now()->subDays(28)->format('Y-m-d');
        $endDate = $options['end_date'] ?? now()->subDays(1)->format('Y-m-d');
        $dimensions = $options['dimensions'] ?? ['query', 'page'];
        $rowLimit = $options['row_limit'] ?? 100;

        $body = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
        ];

        if (isset($options['dimension_filter'])) {
            $body['dimensionFilterGroups'] = [[
                'filters' => [
                    [
                        'dimension' => $options['dimension_filter']['dimension'],
                        'operator' => $options['dimension_filter']['operator'] ?? 'contains',
                        'expression' => $options['dimension_filter']['expression'],
                    ],
                ],
            ]];
        }

        $response = Http::withToken($token)
            ->post(self::SEARCH_CONSOLE_API."/sites/{$siteUrl}/searchAnalytics/query", $body);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch search analytics: '.$response->body());
        }

        return $response->json()['rows'] ?? [];
    }

    /**
     * Get ranking data for specific keywords
     */
    public function getKeywordRankings(
        User $user,
        string $siteUrl,
        array $keywords,
        int $days = 28
    ): array {
        $rankings = [];

        foreach ($keywords as $keyword) {
            $data = $this->getSearchAnalytics($user, $siteUrl, [
                'start_date' => now()->subDays($days)->format('Y-m-d'),
                'end_date' => now()->subDays(1)->format('Y-m-d'),
                'dimensions' => ['query', 'date'],
                'dimension_filter' => [
                    'dimension' => 'query',
                    'operator' => 'equals',
                    'expression' => $keyword,
                ],
            ]);

            $rankings[$keyword] = [
                'keyword' => $keyword,
                'data' => $data,
                'avg_position' => $this->calculateAveragePosition($data),
                'total_impressions' => array_sum(array_column($data, 'impressions')),
                'total_clicks' => array_sum(array_column($data, 'clicks')),
                'avg_ctr' => $this->calculateAverageCtr($data),
            ];
        }

        return $rankings;
    }

    /**
     * Get page performance metrics
     */
    public function getPagePerformance(
        User $user,
        string $siteUrl,
        string $pageUrl,
        int $days = 28
    ): array {
        $data = $this->getSearchAnalytics($user, $siteUrl, [
            'start_date' => now()->subDays($days)->format('Y-m-d'),
            'end_date' => now()->subDays(1)->format('Y-m-d'),
            'dimensions' => ['query'],
            'dimension_filter' => [
                'dimension' => 'page',
                'operator' => 'equals',
                'expression' => $pageUrl,
            ],
            'row_limit' => 50,
        ]);

        return [
            'page_url' => $pageUrl,
            'keywords' => $data,
            'total_impressions' => array_sum(array_column($data, 'impressions')),
            'total_clicks' => array_sum(array_column($data, 'clicks')),
            'avg_position' => $this->calculateAveragePosition($data),
            'top_keywords' => array_slice($data, 0, 10),
        ];
    }

    /**
     * Get index coverage status
     */
    public function getIndexCoverage(User $user, string $siteUrl): array
    {
        // Note: Index coverage requires the newer Inspection API
        // This returns what we can get from search analytics
        $token = $this->oauth->getValidAccessToken($user);
        if (! $token) {
            throw new \Exception('Google account not connected');
        }

        // Get unique pages from search analytics as proxy for indexed pages
        $pages = $this->getSearchAnalytics($user, $siteUrl, [
            'dimensions' => ['page'],
            'row_limit' => 1000,
        ]);

        return [
            'indexed_pages_sample' => count($pages),
            'pages' => array_map(fn ($p) => $p['keys'][0] ?? '', $pages),
        ];
    }

    /**
     * Track SEO KPIs for a page over time
     */
    public function trackSeoKpis(
        User $user,
        string $siteUrl,
        string $pageUrl
    ): array {
        $cacheKey = "seo_kpis_{$siteUrl}_{$pageUrl}";

        // Get current period (last 28 days)
        $current = $this->getPagePerformance($user, $siteUrl, $pageUrl, 28);

        // Get previous period for comparison
        $previousData = $this->getSearchAnalytics($user, $siteUrl, [
            'start_date' => now()->subDays(56)->format('Y-m-d'),
            'end_date' => now()->subDays(29)->format('Y-m-d'),
            'dimensions' => ['query'],
            'dimension_filter' => [
                'dimension' => 'page',
                'operator' => 'equals',
                'expression' => $pageUrl,
            ],
        ]);

        $previousImpressions = array_sum(array_column($previousData, 'impressions'));
        $previousClicks = array_sum(array_column($previousData, 'clicks'));

        return [
            'page_url' => $pageUrl,
            'period' => 'last_28_days',
            'impressions' => [
                'current' => $current['total_impressions'],
                'previous' => $previousImpressions,
                'change_pct' => $previousImpressions > 0
                    ? round((($current['total_impressions'] - $previousImpressions) / $previousImpressions) * 100, 1)
                    : null,
            ],
            'clicks' => [
                'current' => $current['total_clicks'],
                'previous' => $previousClicks,
                'change_pct' => $previousClicks > 0
                    ? round((($current['total_clicks'] - $previousClicks) / $previousClicks) * 100, 1)
                    : null,
            ],
            'avg_position' => $current['avg_position'],
            'top_keywords' => $current['top_keywords'],
        ];
    }

    /**
     * Get SEO performance summary for PSEO pages
     */
    public function getPseoPerformance(User $user, string $siteUrl): array
    {
        // Get all pages, filter for programmatic SEO patterns
        $allPages = $this->getSearchAnalytics($user, $siteUrl, [
            'dimensions' => ['page'],
            'row_limit' => 1000,
        ]);

        // Filter for common PSEO URL patterns
        $pseoPages = array_filter($allPages, function ($page) {
            $url = $page['keys'][0] ?? '';

            // Match patterns like /services/*, /industries/*, /locations/*
            return preg_match('/(services|industries|locations|solutions)\//i', $url);
        });

        $totalImpressions = array_sum(array_column($pseoPages, 'impressions'));
        $totalClicks = array_sum(array_column($pseoPages, 'clicks'));

        return [
            'total_pseo_pages' => count($pseoPages),
            'total_impressions' => $totalImpressions,
            'total_clicks' => $totalClicks,
            'avg_ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
            'top_performing' => array_slice(
                array_values(array_filter($pseoPages, fn ($p) => ($p['clicks'] ?? 0) > 0)),
                0,
                10
            ),
        ];
    }

    /**
     * Google Analytics: Get page views and engagement
     */
    public function getAnalyticsPageViews(
        User $user,
        string $propertyId,
        array $options = []
    ): array {
        $token = $this->oauth->getValidAccessToken($user);
        if (! $token) {
            throw new \Exception('Google account not connected');
        }

        $startDate = $options['start_date'] ?? '28daysAgo';
        $endDate = $options['end_date'] ?? 'yesterday';

        $body = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [['name' => 'pagePath']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
            'limit' => $options['limit'] ?? 100,
        ];

        if (isset($options['page_filter'])) {
            $body['dimensionFilter'] = [
                'filter' => [
                    'fieldName' => 'pagePath',
                    'stringFilter' => [
                        'matchType' => 'CONTAINS',
                        'value' => $options['page_filter'],
                    ],
                ],
            ];
        }

        $response = Http::withToken($token)
            ->post(self::ANALYTICS_API."/properties/{$propertyId}:runReport", $body);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch analytics: '.$response->body());
        }

        return $this->parseAnalyticsResponse($response->json());
    }

    /**
     * Get conversion data for SEO pages
     */
    public function getSeoConversions(
        User $user,
        string $propertyId,
        string $conversionEvent = 'form_submit'
    ): array {
        $token = $this->oauth->getValidAccessToken($user);
        if (! $token) {
            throw new \Exception('Google account not connected');
        }

        $body = [
            'dateRanges' => [['startDate' => '28daysAgo', 'endDate' => 'yesterday']],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'sessionSource'],
            ],
            'metrics' => [
                ['name' => 'eventCount'],
            ],
            'dimensionFilter' => [
                'andGroup' => [
                    'expressions' => [
                        [
                            'filter' => [
                                'fieldName' => 'eventName',
                                'stringFilter' => [
                                    'matchType' => 'EXACT',
                                    'value' => $conversionEvent,
                                ],
                            ],
                        ],
                        [
                            'filter' => [
                                'fieldName' => 'sessionSource',
                                'stringFilter' => [
                                    'matchType' => 'EXACT',
                                    'value' => 'google',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::withToken($token)
            ->post(self::ANALYTICS_API."/properties/{$propertyId}:runReport", $body);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch conversions: '.$response->body());
        }

        return $this->parseAnalyticsResponse($response->json());
    }

    private function calculateAveragePosition(array $data): float
    {
        if (empty($data)) {
            return 0;
        }

        $totalPosition = 0;
        $totalImpressions = 0;

        foreach ($data as $row) {
            $impressions = $row['impressions'] ?? 0;
            $position = $row['position'] ?? 0;
            $totalPosition += $position * $impressions;
            $totalImpressions += $impressions;
        }

        return $totalImpressions > 0 ? round($totalPosition / $totalImpressions, 1) : 0;
    }

    private function calculateAverageCtr(array $data): float
    {
        $totalImpressions = array_sum(array_column($data, 'impressions'));
        $totalClicks = array_sum(array_column($data, 'clicks'));

        return $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0;
    }

    private function parseAnalyticsResponse(array $response): array
    {
        $rows = $response['rows'] ?? [];
        $parsed = [];

        foreach ($rows as $row) {
            $dimensions = array_map(fn ($d) => $d['value'], $row['dimensionValues'] ?? []);
            $metrics = array_map(fn ($m) => $m['value'], $row['metricValues'] ?? []);

            $parsed[] = [
                'page' => $dimensions[0] ?? '',
                'source' => $dimensions[1] ?? null,
                'page_views' => (int) ($metrics[0] ?? 0),
                'sessions' => (int) ($metrics[1] ?? 0),
                'bounce_rate' => (float) ($metrics[2] ?? 0),
                'avg_duration' => (float) ($metrics[3] ?? 0),
            ];
        }

        return $parsed;
    }
}
