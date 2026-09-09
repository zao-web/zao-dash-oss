<?php

namespace App\Agents\Tools;

use App\Services\Seo\SeoPerformanceService;

/**
 * Provide performance feedback to agent for learning and optimization.
 *
 * Returns insights on what content types, keywords, and pages perform best
 * so the agent can adjust its content generation strategy.
 */
class SeoPerformanceFeedbackTool extends BaseTool
{
    public function category(): string
    {
        return 'seo';
    }

    public function id(): string
    {
        return 'seo-performance-feedback';
    }

    public function name(): string
    {
        return 'SEO Performance Feedback';
    }

    public function description(): string
    {
        return 'Get performance feedback on what content types, keywords, and strategies are working best. Use this to learn and improve future content generation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'enum' => ['week', 'month', 'quarter'],
                    'description' => 'Time period for analysis (default: week)',
                    'default' => 'week',
                ],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return false; // Reading performance data doesn't need approval
    }

    public function riskLevel(): string
    {
        return 'low';
    }

    public function execute(array $params): array
    {
        $performanceService = app(SeoPerformanceService::class);

        $period = $params['period'] ?? 'week';
        $startDate = match ($period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'quarter' => now()->startOfQuarter(),
            default => now()->startOfWeek(),
        };

        try {
            // Get comprehensive performance data
            $heroMetrics = $performanceService->getHeroMetrics($startDate);
            $contentComparison = $performanceService->getContentComparison($startDate);
            $topPages = $performanceService->getTopPerformingPages(10, $startDate);
            $topKeywords = $performanceService->getKeywordPerformance(10, $startDate);
            $programmaticVsManual = $performanceService->getProgrammaticVsManual($startDate);
            $weeklyReport = $performanceService->getWeeklyReport();

            // Analyze what's working
            $insights = $this->generateInsights(
                $contentComparison,
                $topPages,
                $topKeywords,
                $programmaticVsManual
            );

            return [
                'success' => true,
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'summary' => [
                    'total_leads' => $heroMetrics['leads'],
                    'total_revenue' => $heroMetrics['revenue_formatted'],
                    'roi' => $heroMetrics['roi_formatted'],
                    'cost_per_lead' => $heroMetrics['cost_per_lead_formatted'],
                ],
                'content_performance' => [
                    'service_pages' => [
                        'count' => $contentComparison['service_pages']['count'],
                        'conversion_rate' => $contentComparison['service_pages']['conversion_rate_formatted'],
                        'leads' => $contentComparison['service_pages']['leads'],
                        'revenue' => $contentComparison['service_pages']['revenue_formatted'],
                    ],
                    'blog_posts' => [
                        'count' => $contentComparison['blog_posts']['count'],
                        'conversion_rate' => $contentComparison['blog_posts']['conversion_rate_formatted'],
                        'leads' => $contentComparison['blog_posts']['leads'],
                        'revenue' => $contentComparison['blog_posts']['revenue_formatted'],
                    ],
                    'comparison_pages' => [
                        'count' => $contentComparison['comparison_pages']['count'],
                        'conversion_rate' => $contentComparison['comparison_pages']['conversion_rate_formatted'],
                        'leads' => $contentComparison['comparison_pages']['leads'],
                        'revenue' => $contentComparison['comparison_pages']['revenue_formatted'],
                    ],
                ],
                'top_performing_pages' => array_map(function ($page) {
                    return [
                        'title' => $page['page_title'],
                        'keyword' => $page['target_keyword'],
                        'leads' => $page['leads'],
                        'revenue' => $page['revenue_formatted'],
                        'conversion_rate' => $page['conversion_rate_formatted'],
                    ];
                }, $topPages),
                'top_keywords' => array_map(function ($keyword) {
                    return [
                        'keyword' => $keyword['keyword'],
                        'position' => $keyword['position'],
                        'leads' => $keyword['leads'],
                        'revenue' => $keyword['revenue_formatted'],
                        'intent' => $keyword['intent'],
                    ];
                }, $topKeywords),
                'programmatic_performance' => [
                    'pages_generated' => $programmaticVsManual['programmatic']['count'],
                    'conversion_rate' => $programmaticVsManual['programmatic']['conversion_rate_formatted'],
                    'leads' => $programmaticVsManual['programmatic']['leads'],
                    'revenue' => $programmaticVsManual['programmatic']['revenue_formatted'],
                    'roi' => $programmaticVsManual['programmatic']['roi_formatted'],
                ],
                'insights' => $insights,
                'recommendations' => $weeklyReport['recommendations'],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate actionable insights from performance data.
     */
    protected function generateInsights(
        array $contentComparison,
        array $topPages,
        array $topKeywords,
        array $programmaticVsManual
    ): array {
        $insights = [];

        // Best performing content type
        $contentTypes = [
            'service_pages' => $contentComparison['service_pages'],
            'blog_posts' => $contentComparison['blog_posts'],
            'comparison_pages' => $contentComparison['comparison_pages'],
        ];

        $bestType = collect($contentTypes)
            ->filter(fn ($type) => $type['count'] > 0)
            ->sortByDesc('conversion_rate')
            ->keys()
            ->first();

        if ($bestType) {
            $typeName = ucfirst(str_replace('_', ' ', $bestType));
            $convRate = $contentTypes[$bestType]['conversion_rate_formatted'];
            $insights[] = [
                'type' => 'best_content_type',
                'priority' => 'high',
                'message' => "{$typeName} have the highest conversion rate at {$convRate}. Focus on creating more of this type.",
            ];
        }

        // Keyword intent analysis
        if (! empty($topKeywords)) {
            $intents = collect($topKeywords)->groupBy('intent')->map->count();
            $topIntent = $intents->sortDesc()->keys()->first();

            if ($topIntent) {
                $insights[] = [
                    'type' => 'keyword_intent',
                    'priority' => 'medium',
                    'message' => "Keywords with '{$topIntent}' intent are performing best. Target more {$topIntent} keywords.",
                ];
            }
        }

        // Programmatic vs manual ROI
        if ($programmaticVsManual['programmatic']['count'] > 0 && $programmaticVsManual['manual']['count'] > 0) {
            $progRoi = $programmaticVsManual['programmatic']['roi'] ?? 0;
            $manualRoi = $programmaticVsManual['manual']['roi'] ?? 0;

            if ($progRoi > $manualRoi) {
                $insights[] = [
                    'type' => 'generation_method',
                    'priority' => 'high',
                    'message' => "Programmatic content has {$progRoi}x ROI vs {$manualRoi}x for manual. Continue AI generation at scale.",
                ];
            }
        }

        // Top keyword patterns
        if (! empty($topKeywords)) {
            $keywords = collect($topKeywords)->pluck('keyword')->take(3)->implode(', ');
            $insights[] = [
                'type' => 'top_keywords',
                'priority' => 'medium',
                'message' => "Top converting keywords: {$keywords}. Create similar variations.",
            ];
        }

        return $insights;
    }
}
