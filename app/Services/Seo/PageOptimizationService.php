<?php

namespace App\Services\Seo;

use App\Models\SeoPage;
use App\Models\SeoPerformanceHistory;
use Illuminate\Support\Collection;

class PageOptimizationService
{
    /**
     * Identify pages that need optimization.
     */
    public function identifyOptimizationOpportunities(): array
    {
        return [
            'underperforming' => $this->getUnderperformingPages(),
            'low_ctr' => $this->getLowCtrPages(),
            'high_bounce' => $this->getHighBouncePages(),
            'missing_conversions' => $this->getPagesWithTrafficButNoConversions(),
            'keyword_cannibalization' => $this->detectKeywordCannibalization(),
            'sunset_candidates' => $this->getSunsetCandidates(),
        ];
    }

    /**
     * Get pages with good traffic but zero conversions.
     */
    public function getUnderperformingPages(int $minClicks = 50): Collection
    {
        return SeoPage::where('clicks_30d', '>', $minClicks)
            ->where('total_leads', 0)
            ->where('status', 'active')
            ->where('published_at', '<=', now()->subDays(30))
            ->orderByDesc('clicks_30d')
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title,
                    'target_keyword' => $page->target_keyword,
                    'clicks_30d' => $page->clicks_30d,
                    'issue' => 'high_traffic_no_conversions',
                    'recommendation' => 'Add prominent CTA, lead magnet, or improve content relevance',
                    'priority' => 'high',
                ];
            });
    }

    /**
     * Get pages with good rankings but low click-through rate.
     */
    public function getLowCtrPages(int $maxPosition = 5, float $minCtr = 2.0): Collection
    {
        return SeoPage::where('avg_position_30d', '<', $maxPosition)
            ->where('ctr_30d', '<', $minCtr)
            ->where('status', 'active')
            ->where('published_at', '<=', now()->subDays(30))
            ->orderBy('ctr_30d')
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title,
                    'target_keyword' => $page->target_keyword,
                    'position' => round($page->avg_position_30d, 1),
                    'ctr' => round($page->ctr_30d, 2),
                    'issue' => 'low_ctr_despite_ranking',
                    'recommendation' => 'Rewrite meta title/description to be more compelling and include target keyword',
                    'priority' => 'medium',
                ];
            });
    }

    /**
     * Get pages with high bounce rate from GA4 data.
     */
    public function getHighBouncePages(float $minBounceRate = 70.0): Collection
    {
        // Get pages with recent bounce rate data from weekly snapshots
        $recentSnapshots = SeoPerformanceHistory::select('seo_page_id')
            ->selectRaw('AVG(bounce_rate) as avg_bounce_rate')
            ->selectRaw('MAX(snapshot_date) as last_snapshot')
            ->where('snapshot_date', '>=', now()->subDays(30))
            ->where('bounce_rate', '>', 0) // Exclude pages without GA4 data
            ->groupBy('seo_page_id')
            ->having('avg_bounce_rate', '>=', $minBounceRate)
            ->get();

        $pageIds = $recentSnapshots->pluck('seo_page_id');

        return SeoPage::whereIn('id', $pageIds)
            ->where('status', 'active')
            ->where('clicks_30d', '>', 20) // Only pages with decent traffic
            ->get()
            ->map(function ($page) use ($recentSnapshots) {
                $snapshot = $recentSnapshots->firstWhere('seo_page_id', $page->id);

                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title,
                    'target_keyword' => $page->target_keyword,
                    'bounce_rate' => round($snapshot->avg_bounce_rate ?? 0, 1),
                    'clicks' => $page->clicks_30d,
                    'issue' => 'high_bounce_rate',
                    'recommendation' => 'Improve content relevance, add internal links, clarify value proposition',
                    'priority' => 'medium',
                ];
            });
    }

    /**
     * Get pages with traffic but no conversions.
     */
    public function getPagesWithTrafficButNoConversions(int $minClicks = 25): Collection
    {
        return SeoPage::where('clicks_30d', '>', $minClicks)
            ->where('total_leads', 0)
            ->where('status', 'active')
            ->where('published_at', '<=', now()->subDays(14))
            ->orderByDesc('clicks_30d')
            ->limit(20)
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title,
                    'clicks' => $page->clicks_30d,
                    'recommendation' => $this->generateConversionOptimization($page),
                ];
            });
    }

    /**
     * Detect keyword cannibalization (multiple pages targeting same keyword).
     */
    public function detectKeywordCannibalization(): Collection
    {
        $keywords = SeoPage::where('status', 'active')
            ->whereNotNull('target_keyword')
            ->get()
            ->groupBy(fn ($page) => strtolower($page->target_keyword))
            ->filter(fn ($pages) => $pages->count() > 1);

        return $keywords->map(function ($pages, $keyword) {
            return [
                'keyword' => $keyword,
                'pages_count' => $pages->count(),
                'pages' => $pages->map(fn ($p) => [
                    'id' => $p->id,
                    'url' => $p->page_url,
                    'position' => $p->avg_position_30d,
                    'clicks' => $p->clicks_30d,
                ])->toArray(),
                'recommendation' => 'Consolidate into one authoritative page or differentiate keywords',
                'priority' => 'high',
            ];
        })->values();
    }

    /**
     * Get pages that should be sunset (removed/archived).
     */
    public function getSunsetCandidates(int $minDays = 60): Collection
    {
        $cutoffDate = now()->subDays($minDays);

        return SeoPage::where('status', 'active')
            ->where('published_at', '<=', $cutoffDate)
            ->where('clicks_30d', 0)
            ->where('total_leads', 0)
            ->orderBy('published_at')
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title,
                    'target_keyword' => $page->target_keyword,
                    'days_since_published' => now()->diffInDays($page->published_at),
                    'issue' => 'no_traffic_or_conversions',
                    'recommendation' => 'Archive or 301 redirect to relevant page',
                    'priority' => 'low',
                ];
            });
    }

    /**
     * Generate specific optimization recommendations for a page.
     */
    public function generateOptimizationPlan(SeoPage $page): array
    {
        $optimizations = [];

        // Check if underperforming
        if ($page->clicks_30d > 50 && $page->total_leads === 0) {
            $optimizations[] = [
                'type' => 'conversion',
                'priority' => 'high',
                'issue' => 'High traffic but zero conversions',
                'actions' => [
                    'Add prominent CTA above the fold',
                    'Add lead magnet (downloadable guide, checklist, etc.)',
                    'Improve content relevance to target keyword',
                    'Add social proof (testimonials, case studies)',
                    'Simplify contact form',
                ],
            ];
        }

        // Check CTR
        if ($page->avg_position_30d < 5 && $page->ctr_30d < 2) {
            $optimizations[] = [
                'type' => 'ctr',
                'priority' => 'high',
                'issue' => 'Ranking well but low click-through rate',
                'actions' => [
                    'Rewrite meta title to be more compelling',
                    'Include target keyword in meta title',
                    'Optimize meta description with clear value proposition',
                    'Add power words and numbers',
                    'Match search intent in title/description',
                ],
            ];
        }

        // Check keyword placement
        if ($page->target_keyword) {
            $optimizations[] = [
                'type' => 'on_page_seo',
                'priority' => 'medium',
                'issue' => 'Standard SEO optimization',
                'actions' => [
                    'Ensure keyword in first 100 words',
                    'Add keyword to H1, H2 tags',
                    'Optimize image alt text with keyword',
                    'Add internal links to related pages',
                    'Add FAQ schema',
                ],
            ];
        }

        // Check content length
        if ($page->content_length && $page->content_length < 800) {
            $optimizations[] = [
                'type' => 'content_depth',
                'priority' => 'medium',
                'issue' => 'Content too thin',
                'actions' => [
                    'Expand content to 1200+ words',
                    'Add more examples and details',
                    'Include data and statistics',
                    'Add visual content',
                ],
            ];
        }

        return [
            'page_id' => $page->id,
            'page_url' => $page->page_url,
            'page_title' => $page->meta_title,
            'target_keyword' => $page->target_keyword,
            'current_metrics' => [
                'position' => round($page->avg_position_30d ?? 0, 1),
                'clicks_30d' => $page->clicks_30d,
                'ctr_30d' => round($page->ctr_30d ?? 0, 2),
                'total_leads' => $page->total_leads,
                'conversion_rate' => round($page->conversion_rate ?? 0, 2),
            ],
            'optimizations' => $optimizations,
            'estimated_impact' => $this->estimateOptimizationImpact($page, $optimizations),
        ];
    }

    /**
     * Estimate the potential impact of optimizations.
     */
    protected function estimateOptimizationImpact(SeoPage $page, array $optimizations): array
    {
        $impact = [
            'potential_traffic_increase' => 0,
            'potential_conversion_increase' => 0,
            'potential_revenue_increase' => 0,
        ];

        foreach ($optimizations as $optimization) {
            if ($optimization['type'] === 'ctr' && $page->impressions_30d > 0) {
                // Improving CTR from 1% to 3% could double or triple traffic
                $currentClicks = $page->clicks_30d;
                $potentialClicks = $page->impressions_30d * 0.03;
                $impact['potential_traffic_increase'] += max(0, $potentialClicks - $currentClicks);
            }

            if ($optimization['type'] === 'conversion') {
                // Assume 1% conversion rate improvement
                $potentialLeads = $page->clicks_30d * 0.01;
                $impact['potential_conversion_increase'] += $potentialLeads;

                // Estimate revenue (assuming avg project value)
                $avgProjectValue = 10000; // From plan estimates
                $impact['potential_revenue_increase'] += $potentialLeads * 0.25 * $avgProjectValue;
            }
        }

        return [
            'traffic_increase' => round($impact['potential_traffic_increase']),
            'conversion_increase' => round($impact['potential_conversion_increase'], 1),
            'revenue_increase_formatted' => '$'.number_format($impact['potential_revenue_increase'], 0),
            'confidence' => 'medium',
        ];
    }

    /**
     * Generate conversion optimization recommendation for a page.
     */
    protected function generateConversionOptimization(SeoPage $page): string
    {
        $recommendations = [];

        if ($page->page_type === 'blog_post') {
            $recommendations[] = 'Convert informational blog into lead capture with relevant CTA';
        } elseif ($page->page_type === 'service_page') {
            $recommendations[] = 'Add contact form and consultation booking CTA';
        } elseif ($page->page_type === 'comparison') {
            $recommendations[] = 'Add "Get Recommendation" CTA at end of comparison';
        }

        $recommendations[] = 'Add lead magnet specific to topic';
        $recommendations[] = 'Include social proof above CTA';

        return implode('; ', $recommendations);
    }

    /**
     * Mark page for archival/sunset.
     */
    public function markForSunset(SeoPage $page, string $reason): void
    {
        $page->update([
            'status' => 'sunset_pending',
            'optimization_notes' => json_encode([
                'sunset_reason' => $reason,
                'sunset_marked_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Archive underperforming page.
     */
    public function archivePage(SeoPage $page): void
    {
        $page->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);
    }
}
