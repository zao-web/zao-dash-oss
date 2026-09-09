<?php

namespace App\Agents\Tools;

use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;

/**
 * Search Slack messages for project research.
 *
 * Use for gathering context about past projects, client communications,
 * technical decisions, and team discussions.
 */
class SearchSlackTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function id(): string
    {
        return 'search-slack';
    }

    public function name(): string
    {
        return 'Search Slack';
    }

    public function description(): string
    {
        return 'Search Slack messages for project research, client discussions, and team communications. Use for gathering context about past work, decisions, and outcomes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query. Supports Slack search operators: from:@user, in:#channel, before:2024-01-01, after:2024-01-01, has:link, has:reaction',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum results to return (default: 20, max: 100)',
                    'default' => 20,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $workspace = SlackWorkspace::first();

        if (! $workspace) {
            return [
                'success' => false,
                'error' => 'No Slack workspace connected. Go to Settings → Integrations to connect Slack.',
            ];
        }

        $slack = app(SlackService::class);
        $limit = min($params['limit'] ?? 20, 100);

        $result = $slack->searchMessages($workspace, $params['query'], $limit);

        if (! $result['ok']) {
            // search:read scope may not be available with bot token
            if ($result['error'] === 'missing_scope') {
                return [
                    'success' => false,
                    'error' => 'Slack search requires user token with search:read scope. Current token may be bot-only.',
                    'suggestion' => 'Re-authorize Slack with user scopes, or search manually and paste relevant context.',
                ];
            }

            return [
                'success' => false,
                'error' => 'Slack search failed: '.$result['error'],
            ];
        }

        return [
            'success' => true,
            'query' => $params['query'],
            'total_matches' => $result['total'],
            'messages' => $result['messages'],
            'note' => $result['total'] > $limit
                ? "Showing {$limit} of {$result['total']} results. Refine query for more specific results."
                : null,
        ];
    }
}
