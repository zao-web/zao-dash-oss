<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentSuggestion;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;

class QuarterlyPatternAnalysisService
{
    /**
     * Run full quarterly analysis.
     */
    public function analyze(?Carbon $quarterStart = null): array
    {
        $quarterStart = $quarterStart ?? now()->startOfQuarter();
        $quarterEnd = $quarterStart->copy()->endOfQuarter();

        return [
            'period' => [
                'start' => $quarterStart->toDateString(),
                'end' => $quarterEnd->toDateString(),
                'label' => 'Q'.$quarterStart->quarter.' '.$quarterStart->year,
            ],
            'industry_patterns' => $this->analyzeIndustryPatterns($quarterStart, $quarterEnd),
            'service_patterns' => $this->analyzeServicePatterns($quarterStart, $quarterEnd),
            'technology_patterns' => $this->analyzeTechnologyPatterns($quarterStart, $quarterEnd),
            'client_growth_patterns' => $this->analyzeClientGrowthPatterns($quarterStart, $quarterEnd),
            'content_suggestions' => $this->generateContentSuggestions($quarterStart, $quarterEnd),
            'landing_page_suggestions' => $this->generateLandingPageSuggestions($quarterStart, $quarterEnd),
            'outreach_suggestions' => $this->generateOutreachSuggestions($quarterStart, $quarterEnd),
        ];
    }

    /**
     * Analyze which industries we've served most.
     */
    public function analyzeIndustryPatterns(Carbon $start, Carbon $end): array
    {
        $projects = Project::whereBetween('created_at', [$start, $end])
            ->with('client')
            ->get();

        $industries = $projects->groupBy(fn ($p) => $p->client?->industry ?? 'Unknown')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'revenue' => $group->sum('budget'),
                'clients' => $group->pluck('client.name')->unique()->values(),
            ])
            ->sortByDesc('count')
            ->toArray();

        return $industries;
    }

    /**
     * Analyze service types delivered.
     */
    public function analyzeServicePatterns(Carbon $start, Carbon $end): array
    {
        $entries = TimeEntry::whereBetween('spent_date', [$start, $end])
            ->with('taskCategory')
            ->get();

        $services = $entries->groupBy(fn ($e) => $e->taskCategory?->name ?? 'General')
            ->map(fn ($group) => [
                'hours' => round($group->sum('hours'), 1),
                'revenue' => $group->sum(fn ($e) => $e->hours * ($e->billable_rate ?? 0)),
                'projects' => $group->pluck('project_id')->unique()->count(),
            ])
            ->sortByDesc('hours')
            ->toArray();

        return $services;
    }

    /**
     * Analyze technologies used.
     */
    public function analyzeTechnologyPatterns(Carbon $start, Carbon $end): array
    {
        $projects = Project::whereBetween('created_at', [$start, $end])
            ->whereNotNull('technologies')
            ->get();

        $techs = [];
        foreach ($projects as $project) {
            foreach ($project->technologies ?? [] as $tech) {
                $techs[$tech] = ($techs[$tech] ?? 0) + 1;
            }
        }

        arsort($techs);

        return $techs;
    }

    /**
     * Analyze client growth patterns.
     */
    public function analyzeClientGrowthPatterns(Carbon $start, Carbon $end): array
    {
        $newClients = Client::whereBetween('created_at', [$start, $end])->count();
        $churnedClients = Client::where('status', 'churned')
            ->whereBetween('updated_at', [$start, $end])
            ->count();

        $expandedClients = Project::whereBetween('created_at', [$start, $end])
            ->whereHas('client', fn ($q) => $q->where('created_at', '<', $start))
            ->distinct('client_id')
            ->count('client_id');

        $avgProjectsPerClient = Project::whereBetween('created_at', [$start, $end])
            ->count() / max(1, Client::count());

        return [
            'new_clients' => $newClients,
            'churned_clients' => $churnedClients,
            'net_growth' => $newClients - $churnedClients,
            'expanded_clients' => $expandedClients,
            'avg_projects_per_client' => round($avgProjectsPerClient, 2),
        ];
    }

    /**
     * Generate content suggestions based on patterns.
     */
    public function generateContentSuggestions(Carbon $start, Carbon $end): array
    {
        $suggestions = [];
        $industries = $this->analyzeIndustryPatterns($start, $end);
        $services = $this->analyzeServicePatterns($start, $end);
        $techs = $this->analyzeTechnologyPatterns($start, $end);

        // Top industry case studies
        $topIndustries = array_slice($industries, 0, 3, true);
        foreach ($topIndustries as $industry => $data) {
            if ($data['count'] >= 2) {
                $suggestions[] = [
                    'type' => 'case_study',
                    'title' => "{$industry} Industry Success Stories",
                    'rationale' => "Worked with {$data['count']} {$industry} clients this quarter",
                    'priority' => $data['count'] * 10,
                ];
            }
        }

        // Top service expertise posts
        $topServices = array_slice($services, 0, 3, true);
        foreach ($topServices as $service => $data) {
            if ($data['hours'] >= 50) {
                $suggestions[] = [
                    'type' => 'blog_post',
                    'title' => "Deep Dive: Our {$service} Expertise",
                    'rationale' => "Logged {$data['hours']} hours on {$service} work",
                    'priority' => (int) ($data['hours'] / 10),
                ];
            }
        }

        // Technology tutorials
        $topTechs = array_slice($techs, 0, 3, true);
        foreach ($topTechs as $tech => $count) {
            if ($count >= 2) {
                $suggestions[] = [
                    'type' => 'tutorial',
                    'title' => "{$tech} Best Practices from Real Projects",
                    'rationale' => "Used {$tech} in {$count} projects this quarter",
                    'priority' => $count * 5,
                ];
            }
        }

        // Sort by priority
        usort($suggestions, fn ($a, $b) => $b['priority'] - $a['priority']);

        return $suggestions;
    }

    /**
     * Generate landing page suggestions.
     */
    public function generateLandingPageSuggestions(Carbon $start, Carbon $end): array
    {
        $suggestions = [];
        $industries = $this->analyzeIndustryPatterns($start, $end);

        foreach ($industries as $industry => $data) {
            if ($data['count'] >= 3 && $industry !== 'Unknown') {
                $suggestions[] = [
                    'type' => 'landing_page',
                    'title' => "WordPress for {$industry}",
                    'rationale' => "Strong track record: {$data['count']} projects, \${$data['revenue']} revenue",
                    'clients' => $data['clients']->toArray(),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Generate outreach suggestions.
     */
    public function generateOutreachSuggestions(Carbon $start, Carbon $end): array
    {
        $suggestions = [];
        $industries = $this->analyzeIndustryPatterns($start, $end);

        // For industries with good results, suggest targeted outreach
        foreach ($industries as $industry => $data) {
            if ($data['count'] >= 2 && $industry !== 'Unknown') {
                $suggestions[] = [
                    'industry' => $industry,
                    'approach' => 'vertical_campaign',
                    'rationale' => "Success with {$data['count']} {$industry} clients",
                    'suggested_icp' => [
                        'industry' => $industry,
                        'company_size' => '10-200 employees',
                        'pain_points' => ['website performance', 'digital growth'],
                    ],
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Save content suggestions to database.
     */
    public function saveSuggestions(array $analysis): int
    {
        $count = 0;

        foreach ($analysis['content_suggestions'] as $suggestion) {
            ContentSuggestion::updateOrCreate(
                ['title' => $suggestion['title'], 'type' => $suggestion['type']],
                [
                    'rationale' => $suggestion['rationale'],
                    'priority' => $suggestion['priority'],
                    'status' => 'pending',
                    'quarter' => $analysis['period']['label'],
                ]
            );
            $count++;
        }

        foreach ($analysis['landing_page_suggestions'] as $suggestion) {
            ContentSuggestion::updateOrCreate(
                ['title' => $suggestion['title'], 'type' => $suggestion['type']],
                [
                    'rationale' => $suggestion['rationale'],
                    'status' => 'pending',
                    'quarter' => $analysis['period']['label'],
                ]
            );
            $count++;
        }

        return $count;
    }
}
