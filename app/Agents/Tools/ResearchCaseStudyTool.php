<?php

namespace App\Agents\Tools;

use App\Agents\ToolRegistry;

/**
 * Research context for case study creation.
 *
 * Aggregates data from multiple sources: Slack, Harvest, web search,
 * and internal project data to build comprehensive case study context.
 */
class ResearchCaseStudyTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'research-case-study';
    }

    public function name(): string
    {
        return 'Research Case Study';
    }

    public function description(): string
    {
        return 'Gather comprehensive context for case study creation. Searches Slack, Harvest time entries, web mentions, and internal data to build a complete picture of project work, outcomes, and learnings.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Name of the project or app (e.g., "Near Me Now", "Travel Portland")',
                ],
                'client_name' => [
                    'type' => 'string',
                    'description' => 'Client organization name',
                ],
                'search_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Additional search terms to look for (technologies, features, etc.)',
                ],
                'date_range' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                        'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    ],
                    'description' => 'Date range for research (defaults to last 3 years)',
                ],
            ],
            'required' => ['project_name'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $registry = app(ToolRegistry::class);
        $projectName = $params['project_name'];
        $clientName = $params['client_name'] ?? null;
        $searchTerms = $params['search_terms'] ?? [];
        $fromDate = $params['date_range']['from'] ?? now()->subYears(3)->toDateString();
        $toDate = $params['date_range']['to'] ?? now()->toDateString();

        $research = [
            'project' => $projectName,
            'client' => $clientName,
            'sources' => [],
        ];

        // 1. Search internal projects
        $projectSearch = $registry->execute('search-projects', [
            'query' => $projectName,
        ]);
        if ($projectSearch['success'] && ! empty($projectSearch['result']['projects'])) {
            $research['sources']['internal_projects'] = $projectSearch['result']['projects'];
        }

        // 2. Search Harvest for time data
        $harvestSearch = $registry->execute('search-harvest', [
            'project_name' => $projectName,
            'client_name' => $clientName,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'include_notes' => true,
        ]);
        if ($harvestSearch['success'] && ($harvestSearch['result']['total_entries'] ?? 0) > 0) {
            $research['sources']['harvest'] = [
                'total_hours' => $harvestSearch['result']['total_hours'],
                'date_range' => $harvestSearch['result']['date_range'],
                'projects' => $harvestSearch['result']['projects'],
            ];
        }

        // 3. Search Slack for discussions
        $slackQueries = [$projectName];
        if ($clientName) {
            $slackQueries[] = $clientName;
        }
        $slackQueries = array_merge($slackQueries, $searchTerms);

        $slackMessages = [];
        foreach (array_slice($slackQueries, 0, 3) as $query) {
            $slackSearch = $registry->execute('search-slack', [
                'query' => $query,
                'limit' => 10,
            ]);
            if ($slackSearch['success'] && ! empty($slackSearch['result']['messages'])) {
                $slackMessages = array_merge($slackMessages, $slackSearch['result']['messages']);
            }
        }
        if (! empty($slackMessages)) {
            $research['sources']['slack'] = [
                'message_count' => count($slackMessages),
                'sample_messages' => array_slice($slackMessages, 0, 15),
            ];
        }

        // 4. Web search for public mentions
        $webQuery = $clientName
            ? "\"{$projectName}\" OR \"{$clientName}\" site:*.com"
            : "\"{$projectName}\"";

        $webSearch = $registry->execute('web-search', [
            'query' => $webQuery,
        ]);
        if ($webSearch['success'] && ! empty($webSearch['result'])) {
            $research['sources']['web'] = $webSearch['result'];
        }

        // 5. Search for related clients
        if ($clientName) {
            $clientSearch = $registry->execute('search-clients', [
                'query' => $clientName,
            ]);
            if ($clientSearch['success'] && ! empty($clientSearch['result']['clients'])) {
                $research['sources']['client_data'] = $clientSearch['result']['clients'];
            }
        }

        // Generate summary
        $research['summary'] = $this->generateSummary($research);

        return [
            'success' => true,
            'research' => $research,
            'next_steps' => [
                'Review the gathered context',
                'Identify key outcomes and metrics',
                'Note any gaps that need manual research',
                'Use seo-generate-blog or content creator agent for drafting',
            ],
        ];
    }

    protected function generateSummary(array $research): array
    {
        $summary = [
            'sources_found' => array_keys($research['sources']),
            'data_quality' => 'incomplete',
        ];

        $hasHarvest = isset($research['sources']['harvest']);
        $hasSlack = isset($research['sources']['slack']);
        $hasWeb = isset($research['sources']['web']);
        $hasInternal = isset($research['sources']['internal_projects']);

        if ($hasHarvest && $hasSlack) {
            $summary['data_quality'] = 'good';
        } elseif ($hasHarvest || $hasSlack) {
            $summary['data_quality'] = 'partial';
        }

        if ($hasHarvest) {
            $summary['total_hours'] = $research['sources']['harvest']['total_hours'];
        }

        if ($hasSlack) {
            $summary['slack_mentions'] = $research['sources']['slack']['message_count'];
        }

        $summary['recommendations'] = [];

        if (! $hasHarvest) {
            $summary['recommendations'][] = 'Sync Harvest data or manually gather time/scope information';
        }

        if (! $hasSlack) {
            $summary['recommendations'][] = 'Check if Slack search scope is enabled, or manually review project channels';
        }

        if (! $hasInternal) {
            $summary['recommendations'][] = 'Add project to internal database for future reference';
        }

        return $summary;
    }
}
