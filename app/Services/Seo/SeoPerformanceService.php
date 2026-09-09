<?php

namespace App\Services\Seo;

use App\Enums\SeoPageStatus;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SeoKeyword;
use App\Models\SeoPage;
use Carbon\Carbon;

class SeoPerformanceService
{
    /**
     * Get comprehensive dashboard KPIs.
     */
    public function getDashboardKpis(): array
    {
        $mtdStart = now()->startOfMonth();

        return [
            'hero_metrics' => $this->getHeroMetrics($mtdStart),
            'conversion_funnel' => $this->getConversionFunnel($mtdStart),
            'top_pages' => $this->getTopPerformingPages(5, $mtdStart),
            'top_keywords' => $this->getKeywordPerformance(5, $mtdStart),
            'content_comparison' => $this->getContentComparison($mtdStart),
            'programmatic_vs_manual' => $this->getProgrammaticVsManual($mtdStart),
            'trends' => $this->getTrends(),
        ];
    }

    /**
     * Get system health metrics for monitoring dashboard.
     */
    public function getSystemHealth(): array
    {
        return [
            'pages_queued' => SeoPage::where('status', SeoPageStatus::Queued)->count(),
            'pages_generating' => SeoPage::where('status', SeoPageStatus::Generating)->count(),
            'pages_stuck' => SeoPage::where('status', SeoPageStatus::Generating)
                ->where('generation_started_at', '<', now()->subHours(1))->count(),
            'pages_failed' => SeoPage::where('status', SeoPageStatus::Failed)
                ->where('created_at', '>', now()->subDays(7))->count(),
            'pages_draft' => SeoPage::where('status', SeoPageStatus::Draft)->count(),
            'pages_published' => SeoPage::where('status', SeoPageStatus::Published)->count(),
            'pages_no_traffic' => SeoPage::where('status', SeoPageStatus::Published)
                ->where('impressions_30d', 0)
                ->where('published_at', '<', now()->subDays(30))->count(),
            'avg_humanization_score' => round(
                SeoPage::whereNotNull('humanization_score')->avg('humanization_score') ?? 0,
                1
            ),
            'avg_word_count' => round(
                SeoPage::whereNotNull('word_count')->avg('word_count') ?? 0,
                0
            ),
            'pages_below_word_count' => SeoPage::where('word_count', '<', 800)->count(),
            'pages_low_humanization' => SeoPage::where('humanization_score', '<', 90)->count(),
            'total_leads_from_seo' => Lead::whereNotNull('seo_page_id')->count(),
            'queue_progress' => $this->getQueueProgress(),
            'top_performing_pages' => SeoPage::where('status', SeoPageStatus::Published)
                ->orderBy('clicks_30d', 'desc')
                ->limit(5)
                ->get(['id', 'page_url', 'target_keyword', 'clicks_30d', 'total_leads'])
                ->toArray(),
            'recent_failures' => SeoPage::where('status', SeoPageStatus::Failed)
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get(['id', 'page_url', 'target_keyword', 'error_message', 'updated_at'])
                ->toArray(),
        ];
    }

    /**
     * Get queue progress by playbook.
     */
    protected function getQueueProgress(): array
    {
        $playbooks = ['Comparisons', 'Vertical', 'Glossary', 'Examples', 'Case Study', 'Integration', 'Curation', 'Tools', 'Persona', 'Location'];
        $progress = [];

        foreach ($playbooks as $playbook) {
            $total = SeoPage::where('playbook', $playbook)->count();
            $published = SeoPage::where('playbook', $playbook)
                ->where('status', SeoPageStatus::Published)
                ->count();

            if ($total > 0) {
                $progress[$playbook] = [
                    'total' => $total,
                    'published' => $published,
                    'queued' => SeoPage::where('playbook', $playbook)
                        ->where('status', SeoPageStatus::Queued)->count(),
                    'generating' => SeoPage::where('playbook', $playbook)
                        ->where('status', SeoPageStatus::Generating)->count(),
                    'percent_complete' => round(($published / $total) * 100, 1),
                ];
            }
        }

        return $progress;
    }

    /**
     * Get hero metrics: Revenue, Leads, Cost/Lead, ROI.
     */
    public function getHeroMetrics(?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        // SEO-attributed leads
        $leads = Lead::where('source', 'organic')
            ->where('created_at', '>=', $startDate)
            ->get();

        $leadsCount = $leads->count();

        // SEO-attributed projects
        $projects = Project::where('source', 'organic')
            ->where('created_at', '>=', $startDate)
            ->get();

        $projectsCount = $projects->count();

        // Total revenue from SEO-attributed projects
        $revenue = $projects->sum('estimated_value') ?? 0;

        // Agent costs (estimate based on page generation)
        $pagesGenerated = SeoPage::where('generated_by_agent', true)
            ->where('published_at', '>=', $startDate)
            ->count();

        $costPerPage = 8.00; // From plan: $8 max budget per generation
        $totalCosts = $pagesGenerated * $costPerPage;

        $costPerLead = $leadsCount > 0 ? $totalCosts / $leadsCount : 0;
        $roi = $totalCosts > 0 ? $revenue / $totalCosts : 0;

        return [
            'revenue' => round($revenue, 2),
            'revenue_formatted' => '$'.number_format($revenue, 0),
            'leads' => $leadsCount,
            'projects' => $projectsCount,
            'cost_per_lead' => round($costPerLead, 2),
            'cost_per_lead_formatted' => '$'.number_format($costPerLead, 0),
            'roi' => round($roi, 1),
            'roi_formatted' => number_format($roi, 1).'x',
            'total_costs' => round($totalCosts, 2),
            'period' => 'MTD',
            'start_date' => $startDate->toDateString(),
        ];
    }

    /**
     * Get conversion funnel: Traffic → Leads → Projects → Revenue.
     */
    public function getConversionFunnel(?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        $traffic = SeoPage::where('published_at', '>=', $startDate)
            ->sum('clicks_30d');

        $leads = Lead::where('source', 'organic')
            ->where('created_at', '>=', $startDate)
            ->count();

        $projects = Project::where('source', 'organic')
            ->where('created_at', '>=', $startDate)
            ->count();

        $revenue = Project::where('source', 'organic')
            ->where('created_at', '>=', $startDate)
            ->sum('estimated_value') ?? 0;

        $trafficToLeads = $traffic > 0 ? ($leads / $traffic) * 100 : 0;
        $leadsToProjects = $leads > 0 ? ($projects / $leads) * 100 : 0;
        $projectsToRevenue = $projects > 0 ? $revenue / $projects : 0;

        return [
            'traffic' => $traffic,
            'leads' => $leads,
            'projects' => $projects,
            'revenue' => round($revenue, 2),
            'revenue_formatted' => '$'.number_format($revenue, 0),
            'traffic_to_leads_rate' => round($trafficToLeads, 2),
            'leads_to_projects_rate' => round($leadsToProjects, 2),
            'avg_project_value' => round($projectsToRevenue, 2),
            'avg_project_value_formatted' => '$'.number_format($projectsToRevenue, 0),
        ];
    }

    /**
     * Get top performing pages by leads/revenue.
     */
    public function getTopPerformingPages(int $limit = 5, ?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        return SeoPage::where('status', 'active')
            ->where('published_at', '>=', $startDate)
            ->orderByDesc('total_revenue')
            ->orderByDesc('total_leads')
            ->limit($limit)
            ->get()
            ->map(function ($page) {
                return [
                    'id' => $page->id,
                    'page_url' => $page->page_url,
                    'page_title' => $page->meta_title ?? $this->extractTitleFromUrl($page->page_url),
                    'target_keyword' => $page->target_keyword,
                    'traffic' => $page->clicks_30d,
                    'leads' => $page->total_leads,
                    'projects' => $page->total_projects,
                    'revenue' => round($page->total_revenue, 2),
                    'revenue_formatted' => '$'.number_format($page->total_revenue, 0),
                    'conversion_rate' => round($page->conversion_rate, 2),
                    'conversion_rate_formatted' => number_format($page->conversion_rate, 2).'%',
                ];
            })
            ->toArray();
    }

    /**
     * Get keyword performance data.
     */
    public function getKeywordPerformance(int $limit = 5, ?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        // Get keywords with associated pages that have leads
        $keywords = SeoKeyword::whereNotNull('seo_page_id')
            ->where('current_position', '<=', 10)
            ->with(['seoPage' => function ($query) use ($startDate) {
                $query->where('published_at', '>=', $startDate);
            }])
            ->get()
            ->filter(fn ($kw) => $kw->seoPage && $kw->seoPage->total_leads > 0)
            ->sortByDesc(fn ($kw) => $kw->seoPage->total_leads)
            ->take($limit)
            ->map(function ($keyword) {
                return [
                    'id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'position' => $keyword->current_position,
                    'position_change' => $keyword->best_position && $keyword->current_position
                        ? $keyword->best_position - $keyword->current_position
                        : 0,
                    'position_change_formatted' => $this->formatPositionChange(
                        $keyword->best_position,
                        $keyword->current_position
                    ),
                    'leads' => $keyword->seoPage->total_leads ?? 0,
                    'revenue' => round($keyword->seoPage->total_revenue ?? 0, 2),
                    'revenue_formatted' => '$'.number_format($keyword->seoPage->total_revenue ?? 0, 0),
                    'intent' => $keyword->intent,
                ];
            })
            ->values()
            ->toArray();

        return $keywords;
    }

    /**
     * Get content performance comparison.
     */
    public function getContentComparison(?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        $servicePages = SeoPage::where('page_type', 'service_page')
            ->where('published_at', '>=', $startDate)
            ->get();

        $blogPosts = SeoPage::where('page_type', 'blog_post')
            ->where('published_at', '>=', $startDate)
            ->get();

        $comparisonPages = SeoPage::where('page_type', 'comparison')
            ->where('published_at', '>=', $startDate)
            ->get();

        return [
            'service_pages' => $this->calculateContentMetrics($servicePages),
            'blog_posts' => $this->calculateContentMetrics($blogPosts),
            'comparison_pages' => $this->calculateContentMetrics($comparisonPages),
        ];
    }

    /**
     * Calculate metrics for a collection of pages.
     */
    protected function calculateContentMetrics($pages): array
    {
        $count = $pages->count();
        $traffic = $pages->sum('clicks_30d');
        $leads = $pages->sum('total_leads');
        $revenue = $pages->sum('total_revenue');
        $conversionRate = $traffic > 0 ? ($leads / $traffic) * 100 : 0;

        return [
            'count' => $count,
            'traffic' => $traffic,
            'leads' => $leads,
            'revenue' => round($revenue, 2),
            'revenue_formatted' => '$'.number_format($revenue, 0),
            'conversion_rate' => round($conversionRate, 2),
            'conversion_rate_formatted' => number_format($conversionRate, 2).'%',
            'avg_revenue_per_page' => $count > 0 ? round($revenue / $count, 2) : 0,
        ];
    }

    /**
     * Compare programmatic vs manual content.
     */
    public function getProgrammaticVsManual(?Carbon $startDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();

        $programmatic = SeoPage::where('generated_by_agent', true)
            ->where('published_at', '>=', $startDate)
            ->get();

        $manual = SeoPage::where('generated_by_agent', false)
            ->where('published_at', '>=', $startDate)
            ->get();

        $programmaticMetrics = $this->calculateContentMetrics($programmatic);
        $manualMetrics = $this->calculateContentMetrics($manual);

        $programmaticMetrics['cost_per_page'] = 8.00;
        $manualMetrics['cost_per_page'] = 500.00; // Estimate from plan

        // ROI comparison
        $programmaticRoi = $programmaticMetrics['cost_per_page'] > 0
            ? ($programmaticMetrics['avg_revenue_per_page'] / $programmaticMetrics['cost_per_page'])
            : 0;

        $manualRoi = $manualMetrics['cost_per_page'] > 0
            ? ($manualMetrics['avg_revenue_per_page'] / $manualMetrics['cost_per_page'])
            : 0;

        return [
            'programmatic' => array_merge($programmaticMetrics, [
                'roi' => round($programmaticRoi, 1),
                'roi_formatted' => number_format($programmaticRoi, 1).'x',
            ]),
            'manual' => array_merge($manualMetrics, [
                'roi' => round($manualRoi, 1),
                'roi_formatted' => number_format($manualRoi, 1).'x',
            ]),
        ];
    }

    /**
     * Get trend data for charts (last 30 days).
     */
    public function getTrends(): array
    {
        $days = 30;
        $startDate = now()->subDays($days)->startOfDay();

        // Generate daily data points
        $leadsTrend = [];
        $revenueTrend = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - $i)->startOfDay();

            $leadsCount = Lead::where('source', 'organic')
                ->whereDate('created_at', $date)
                ->count();

            $revenueAmount = Project::where('source', 'organic')
                ->whereDate('created_at', $date)
                ->sum('estimated_value') ?? 0;

            $leadsTrend[] = [
                'date' => $date->format('M d'),
                'value' => $leadsCount,
            ];

            $revenueTrend[] = [
                'date' => $date->format('M d'),
                'value' => round($revenueAmount, 2),
            ];
        }

        return [
            'leads_trend' => $leadsTrend,
            'revenue_trend' => $revenueTrend,
        ];
    }

    /**
     * Get weekly performance report for agent.
     */
    public function getWeeklyReport(): array
    {
        $weekStart = now()->startOfWeek();

        return [
            'period' => 'Week of '.$weekStart->format('M d, Y'),
            'pages_published' => SeoPage::where('published_at', '>=', $weekStart)->count(),
            'new_leads' => Lead::where('source', 'organic')
                ->where('created_at', '>=', $weekStart)
                ->count(),
            'new_projects' => Project::where('source', 'organic')
                ->where('created_at', '>=', $weekStart)
                ->count(),
            'revenue_generated' => Project::where('source', 'organic')
                ->where('created_at', '>=', $weekStart)
                ->sum('estimated_value') ?? 0,
            'top_performers' => $this->getTopPerformingPages(3, $weekStart),
            'content_performance' => $this->getContentComparison($weekStart),
            'recommendations' => $this->generateRecommendations($weekStart),
        ];
    }

    /**
     * Generate recommendations based on performance data.
     */
    protected function generateRecommendations(Carbon $startDate): array
    {
        $contentComparison = $this->getContentComparison($startDate);
        $recommendations = [];

        // Identify best performing content type
        $types = [
            'service_pages' => $contentComparison['service_pages'],
            'blog_posts' => $contentComparison['blog_posts'],
            'comparison_pages' => $contentComparison['comparison_pages'],
        ];

        $bestType = collect($types)
            ->sortByDesc('conversion_rate')
            ->keys()
            ->first();

        if ($bestType && $types[$bestType]['conversion_rate'] > 0) {
            $recommendations[] = [
                'type' => 'content_focus',
                'priority' => 'high',
                'message' => ucfirst(str_replace('_', ' ', $bestType)).
                    " are converting at {$types[$bestType]['conversion_rate_formatted']}. Create more of this type.",
            ];
        }

        // Check for underperforming pages
        $underperforming = SeoPage::where('clicks_30d', '>', 50)
            ->where('total_leads', 0)
            ->where('published_at', '>=', $startDate)
            ->count();

        if ($underperforming > 0) {
            $recommendations[] = [
                'type' => 'optimization',
                'priority' => 'medium',
                'message' => "{$underperforming} pages have good traffic but zero conversions. Add CTAs or lead magnets.",
            ];
        }

        // Check for pages needing meta optimization
        $needsMetaOptimization = SeoPage::where('avg_position_30d', '<', 5)
            ->where('ctr_30d', '<', 2)
            ->where('published_at', '>=', $startDate)
            ->count();

        if ($needsMetaOptimization > 0) {
            $recommendations[] = [
                'type' => 'optimization',
                'priority' => 'low',
                'message' => "{$needsMetaOptimization} pages ranking well but have low CTR. Improve meta titles/descriptions.",
            ];
        }

        return $recommendations;
    }

    /**
     * Extract title from URL slug.
     */
    protected function extractTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $slug = basename($path ?? '');

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Format position change indicator.
     */
    protected function formatPositionChange(?int $bestPosition, ?int $currentPosition): string
    {
        if (! $bestPosition || ! $currentPosition) {
            return '';
        }

        $change = $bestPosition - $currentPosition;

        if ($change > 0) {
            return "↑{$change}";
        } elseif ($change < 0) {
            return '↓'.abs($change);
        }

        return '—';
    }
}
