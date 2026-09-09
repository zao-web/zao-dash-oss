<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SlackMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProactiveInsightsService
{
    /**
     * Get all proactive insights for the dashboard.
     */
    public function getInsights(int $limit = 6): array
    {
        $insights = collect();

        // 1. Slack signals - budget mentions, expansion, etc.
        $insights = $insights->merge($this->getSlackSignals());

        // 2. Client patterns - vertical concentration
        $insights = $insights->merge($this->getClientPatterns());

        // 3. Project opportunities - case studies, testimonials
        $insights = $insights->merge($this->getProjectOpportunities());

        // 4. Lead signals - hot leads, stale high-value
        $insights = $insights->merge($this->getLeadSignals());

        // 5. Client health signals
        $insights = $insights->merge($this->getClientHealthSignals());

        // 6. Quarterly pattern insights
        $insights = $insights->merge($this->getQuarterlyPatternInsights());

        // Sort by priority and recency, return limited
        return $insights
            ->sortByDesc(fn ($i) => ($i['priority'] ?? 50) + ($i['recency_score'] ?? 0))
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Detect signals from Slack messages.
     */
    protected function getSlackSignals(): Collection
    {
        $insights = collect();

        // Keywords that indicate opportunities
        $opportunityKeywords = ['budget', 'expansion', 'growth', 'new project', 'additional', 'more work', 'scale'];
        $riskKeywords = ['unhappy', 'frustrated', 'delay', 'issue', 'problem', 'concerned', 'disappointed'];

        // Check if SlackMessage table exists
        if (! class_exists(\App\Models\SlackMessage::class)) {
            return $insights;
        }

        try {
            // Look for opportunity signals in recent messages
            $recentMessages = SlackMessage::where('created_at', '>', now()->subDays(7))
                ->whereNotNull('text')
                ->latest()
                ->limit(500)
                ->get();

            foreach ($recentMessages as $message) {
                $text = strtolower($message->text ?? '');

                // Check for opportunity keywords
                foreach ($opportunityKeywords as $keyword) {
                    if (str_contains($text, $keyword)) {
                        $client = $this->extractClientFromChannel($message->channel_name);
                        $insights->push([
                            'type' => 'slack_opportunity',
                            'title' => $client
                                ? "Slack: {$client} mentioned {$keyword}"
                                : 'Slack: Opportunity signal detected',
                            'subtitle' => "Detected {$message->created_at->diffForHumans()} in #{$message->channel_name}",
                            'action' => 'upsell',
                            'action_label' => 'Draft upsell proposal',
                            'agent_slug' => 'upsell-proposal',
                            'priority' => 80,
                            'recency_score' => $this->recencyScore($message->created_at),
                            'metadata' => [
                                'client' => $client,
                                'channel' => $message->channel_name,
                                'keyword' => $keyword,
                            ],
                        ]);
                        break; // One insight per message
                    }
                }

                // Check for risk keywords
                foreach ($riskKeywords as $keyword) {
                    if (str_contains($text, $keyword)) {
                        $client = $this->extractClientFromChannel($message->channel_name);
                        $insights->push([
                            'type' => 'slack_risk',
                            'title' => $client
                                ? "Alert: {$client} may need attention"
                                : 'Alert: Risk signal detected in Slack',
                            'subtitle' => "Detected {$message->created_at->diffForHumans()} in #{$message->channel_name}",
                            'action' => 'review',
                            'action_label' => 'Review conversation',
                            'agent_slug' => 'client-health-monitor',
                            'priority' => 90,
                            'recency_score' => $this->recencyScore($message->created_at),
                            'metadata' => [
                                'client' => $client,
                                'channel' => $message->channel_name,
                                'keyword' => $keyword,
                            ],
                        ]);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Table might not exist or have different schema
        }

        return $insights->unique(fn ($i) => $i['metadata']['channel'] ?? $i['title'])->take(3);
    }

    /**
     * Detect patterns in client data.
     */
    protected function getClientPatterns(): Collection
    {
        $insights = collect();

        // Look for vertical concentration (only if industry column exists)
        // TODO: Add industry column to clients table migration
        try {
            if (Schema::hasColumn('clients', 'industry')) {
                $industryGroups = Client::where('status', 'active')
                    ->whereNotNull('industry')
                    ->where('created_at', '>', now()->subMonths(6))
                    ->select('industry', DB::raw('COUNT(*) as count'))
                    ->groupBy('industry')
                    ->having('count', '>=', 2)
                    ->orderByDesc('count')
                    ->get();
            } else {
                $industryGroups = collect();
            }
        } catch (\Exception $e) {
            $industryGroups = collect();
        }

        foreach ($industryGroups as $group) {
            if ($group->count >= 2) {
                $insights->push([
                    'type' => 'pattern_vertical',
                    'title' => "Pattern: {$group->count} {$group->industry} clients",
                    'subtitle' => 'Opportunity for vertical focus',
                    'action' => 'landing',
                    'action_label' => 'Generate landing page',
                    'agent_slug' => 'landing-page-generator',
                    'priority' => 60,
                    'recency_score' => 20,
                    'metadata' => [
                        'industry' => $group->industry,
                        'count' => $group->count,
                    ],
                ]);
            }
        }

        return $insights->take(2);
    }

    /**
     * Find opportunities from completed projects.
     */
    protected function getProjectOpportunities(): Collection
    {
        $insights = collect();

        // Recently completed projects - case study opportunities
        $completedProjects = Project::with('client')
            ->where('status', 'completed')
            ->where('updated_at', '>', now()->subDays(30))
            ->whereHas('client', fn ($q) => $q->where('status', 'active'))
            ->latest('updated_at')
            ->take(5)
            ->get();

        foreach ($completedProjects as $project) {
            $insights->push([
                'type' => 'project_case_study',
                'title' => "Completed: {$project->name}",
                'subtitle' => 'Case study opportunity',
                'action' => 'case_study',
                'action_label' => 'Draft case study',
                'agent_slug' => 'case-study-writer',
                'priority' => 50,
                'recency_score' => $this->recencyScore($project->updated_at),
                'metadata' => [
                    'project_id' => $project->id,
                    'client' => $project->client?->slug,
                    'client_name' => $project->client?->name,
                ],
            ]);
        }

        return $insights->take(2);
    }

    /**
     * Detect lead signals.
     */
    protected function getLeadSignals(): Collection
    {
        $insights = collect();

        // Hot leads - high value, recently active
        $hotLeads = Lead::where('stage', 'qualified')
            ->where('deal_value', '>', 10000)
            ->where('updated_at', '>', now()->subDays(7))
            ->orderByDesc('deal_value')
            ->take(3)
            ->get();

        foreach ($hotLeads as $lead) {
            $insights->push([
                'type' => 'lead_hot',
                'title' => "Hot lead: {$lead->company_name}",
                'subtitle' => '$'.number_format($lead->deal_value).' opportunity',
                'action' => 'followup',
                'action_label' => 'Send follow-up',
                'agent_slug' => 'lead-nurture',
                'priority' => 70,
                'recency_score' => $this->recencyScore($lead->updated_at),
                'metadata' => [
                    'lead_id' => $lead->id,
                    'company' => $lead->company_name,
                    'value' => $lead->deal_value,
                ],
            ]);
        }

        // Stale high-value leads
        $staleLeads = Lead::whereIn('stage', ['new', 'qualified'])
            ->where('deal_value', '>', 20000)
            ->where(function ($q) {
                $q->whereNull('last_contacted_at')
                    ->orWhere('last_contacted_at', '<', now()->subDays(14));
            })
            ->orderByDesc('deal_value')
            ->take(2)
            ->get();

        foreach ($staleLeads as $lead) {
            $lastContact = $lead->last_contacted_at
                ? $lead->last_contacted_at->diffForHumans()
                : 'Never contacted';

            $insights->push([
                'type' => 'lead_stale',
                'title' => "Re-engage: {$lead->company_name}",
                'subtitle' => "\${$lead->deal_value} deal - {$lastContact}",
                'action' => 'followup',
                'action_label' => 'Draft outreach',
                'agent_slug' => 'lead-nurture',
                'priority' => 65,
                'recency_score' => 10,
                'metadata' => [
                    'lead_id' => $lead->id,
                    'company' => $lead->company_name,
                    'value' => $lead->deal_value,
                ],
            ]);
        }

        return $insights->take(3);
    }

    /**
     * Detect client health signals.
     */
    protected function getClientHealthSignals(): Collection
    {
        $insights = collect();

        // Clients with declining health scores
        $atRiskClients = Client::where('status', 'active')
            ->where('health_score', '<', 50)
            ->orderBy('health_score')
            ->take(3)
            ->get();

        foreach ($atRiskClients as $client) {
            $insights->push([
                'type' => 'client_at_risk',
                'title' => "At risk: {$client->name}",
                'subtitle' => "Health score: {$client->health_score}/100",
                'action' => 'review',
                'action_label' => 'Review client',
                'agent_slug' => 'client-health-monitor',
                'priority' => 85,
                'recency_score' => 30,
                'metadata' => [
                    'client_id' => $client->id,
                    'client_slug' => $client->slug,
                    'health_score' => $client->health_score,
                ],
            ]);
        }

        return $insights->take(2);
    }

    /**
     * Calculate recency score (0-30) based on how recent the event is.
     */
    protected function recencyScore($date): int
    {
        if (! $date) {
            return 0;
        }

        $hoursAgo = now()->diffInHours($date);

        if ($hoursAgo < 6) {
            return 30;
        }
        if ($hoursAgo < 24) {
            return 25;
        }
        if ($hoursAgo < 72) {
            return 20;
        }
        if ($hoursAgo < 168) {
            return 15;
        } // 1 week

        return 10;
    }

    /**
     * Try to extract client name from Slack channel name.
     */
    protected function extractClientFromChannel(?string $channelName): ?string
    {
        if (! $channelName) {
            return null;
        }

        // Try to find a matching client by slug
        $client = Client::where('slug', 'like', str_replace('-', '%', $channelName))
            ->orWhere('name', 'like', '%'.str_replace('-', ' ', $channelName).'%')
            ->first();

        return $client?->name;
    }

    /**
     * Get insights from quarterly pattern analysis.
     */
    protected function getQuarterlyPatternInsights(): Collection
    {
        $insights = collect();

        try {
            $quarterlyService = App::make(QuarterlyPatternAnalysisService::class);
            $analysis = $quarterlyService->analyze();

            // Add landing page suggestions as insights
            foreach ($analysis['landing_page_suggestions'] ?? [] as $suggestion) {
                $insights->push([
                    'type' => 'quarterly_landing',
                    'title' => "Pattern: {$suggestion['title']}",
                    'subtitle' => $suggestion['rationale'],
                    'action' => 'landing',
                    'action_label' => 'Generate landing page',
                    'agent_slug' => 'landing-page-generator',
                    'priority' => 55,
                    'recency_score' => 15,
                    'metadata' => [
                        'quarter' => $analysis['period']['label'],
                        'clients' => $suggestion['clients'] ?? [],
                    ],
                ]);
            }

            // Add top content suggestions
            foreach (array_slice($analysis['content_suggestions'] ?? [], 0, 2) as $suggestion) {
                $action = $suggestion['type'] === 'case_study' ? 'case_study' : 'review';
                $agentSlug = $suggestion['type'] === 'case_study' ? 'case-study-writer' : 'content-creator';

                $insights->push([
                    'type' => 'quarterly_content',
                    'title' => "Content idea: {$suggestion['title']}",
                    'subtitle' => $suggestion['rationale'],
                    'action' => $action,
                    'action_label' => 'Draft content',
                    'agent_slug' => $agentSlug,
                    'priority' => 45 + ($suggestion['priority'] ?? 0) / 10,
                    'recency_score' => 10,
                    'metadata' => [
                        'quarter' => $analysis['period']['label'],
                        'type' => $suggestion['type'],
                    ],
                ]);
            }

            // Add outreach suggestions
            foreach (array_slice($analysis['outreach_suggestions'] ?? [], 0, 1) as $suggestion) {
                $insights->push([
                    'type' => 'quarterly_outreach',
                    'title' => "Target: {$suggestion['industry']} vertical",
                    'subtitle' => $suggestion['rationale'],
                    'action' => 'upsell',
                    'action_label' => 'Plan outreach',
                    'agent_slug' => 'lead-generation',
                    'priority' => 50,
                    'recency_score' => 10,
                    'metadata' => [
                        'quarter' => $analysis['period']['label'],
                        'industry' => $suggestion['industry'],
                        'icp' => $suggestion['suggested_icp'] ?? [],
                    ],
                ]);
            }

        } catch (\Exception $e) {
            // Quarterly service might fail if models don't exist
        }

        return $insights->take(3);
    }
}
