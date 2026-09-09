<?php

namespace App\Mcp\Tools;

use App\Models\SlackUserWatchlistItem;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackWatchlistService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ManageSlackWatchlistTool extends Tool
{
    protected string $name = 'manage-slack-watchlist';

    protected string $title = 'Manage Slack Watchlist';

    protected string $description = 'Track, untrack, list, or clear Slack channels in a private per-user watchlist.';

    public function __construct(
        protected SlackWatchlistService $watchlistService
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'workspace_id' => 'required|string',
            'slack_user_id' => 'required|string',
            'action' => 'required|string|in:track,untrack,list,clear',
            'query' => 'nullable|string',
        ]);

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $request->get('workspace_id'))
            ->firstOrFail();

        $action = (string) $request->get('action');
        $slackUserId = (string) $request->get('slack_user_id');
        $query = trim((string) ($request->get('query') ?? ''));

        return match ($action) {
            'track' => Response::structured($this->watchlistService->trackByQuery($workspace, $slackUserId, $query)),
            'untrack' => Response::structured($this->watchlistService->untrackByQuery($workspace, $slackUserId, $query)),
            'clear' => Response::structured($this->watchlistService->clear($workspace, $slackUserId)),
            'list' => Response::structured([
                'success' => true,
                'message' => 'Loaded Slack watchlist.',
                'items' => $this->watchlistService->listItems($workspace, $slackUserId)
                    ->map(fn (SlackUserWatchlistItem $item): array => [
                        'id' => $item->id,
                        'label' => $item->label,
                        'channel_id' => $item->channel?->channel_id,
                        'channel_name' => $item->channel?->channel_name,
                        'classification' => $item->channel?->classification,
                        'monitoring_enabled' => (bool) $item->channel?->monitoring_enabled,
                        'client' => $item->channel?->client ? [
                            'id' => $item->channel->client->id,
                            'name' => $item->channel->client->name,
                        ] : null,
                        'project' => $item->channel?->project ? [
                            'id' => $item->channel->project->id,
                            'name' => $item->channel->project->name,
                            'status' => $item->channel->project->status,
                        ] : null,
                        'last_accessed_at' => $item->last_accessed_at?->toIso8601String(),
                    ])->values()->all(),
            ]),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->required()->description('Slack workspace/team ID'),
            'slack_user_id' => $schema->string()->required()->description('Slack user ID whose watchlist should be modified'),
            'action' => $schema->string()
                ->required()
                ->enum(['track', 'untrack', 'list', 'clear'])
                ->description('Watchlist action to perform'),
            'query' => $schema->string()->description('Natural language channel, client, or project name to track or untrack'),
        ];
    }
}
