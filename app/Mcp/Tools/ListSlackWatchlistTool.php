<?php

namespace App\Mcp\Tools;

use App\Models\SlackUserWatchlistItem;
use App\Models\SlackWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListSlackWatchlistTool extends Tool
{
    protected string $name = 'list-slack-watchlist';

    protected string $title = 'List Slack Watchlist';

    protected string $description = 'List the Slack channels a specific Slack user is watching in a workspace.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'workspace_id' => 'required|string',
            'slack_user_id' => 'required|string',
        ]);

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $request->get('workspace_id'))
            ->firstOrFail();

        $items = SlackUserWatchlistItem::query()
            ->with(['channel.client', 'channel.project'])
            ->where('workspace_id', $workspace->id)
            ->where('slack_user_id', $request->get('slack_user_id'))
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->get();

        return Response::structured([
            'success' => true,
            'message' => $items->isEmpty()
                ? 'No channels are currently on this watchlist.'
                : 'Loaded Slack watchlist.',
            'workspace' => [
                'id' => $workspace->workspace_id,
                'name' => $workspace->workspace_name,
            ],
            'items' => $items->map(fn (SlackUserWatchlistItem $item): array => [
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
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->required()->description('Slack workspace/team ID'),
            'slack_user_id' => $schema->string()->required()->description('Slack user ID whose watchlist should be loaded'),
        ];
    }
}
