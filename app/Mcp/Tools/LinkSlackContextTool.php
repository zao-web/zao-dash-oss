<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class LinkSlackContextTool extends Tool
{
    protected string $name = 'link-slack-context';

    protected string $title = 'Link Slack Context';

    protected string $description = 'Link a Slack channel to a client and optionally a project so the channel becomes an operational control surface for that work.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'workspace_id' => 'required|string',
            'channel_id' => 'required|string',
            'client_id' => 'nullable|integer|exists:clients,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'monitoring_enabled' => 'nullable|boolean',
        ]);

        if (! $request->filled('client_id') && ! $request->filled('project_id')) {
            return Response::text('Provide at least client_id or project_id.');
        }

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $request->get('workspace_id'))
            ->firstOrFail();

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $request->get('channel_id'))
            ->firstOrFail();

        $project = $request->get('project_id')
            ? Project::query()->with('client')->findOrFail($request->get('project_id'))
            : null;

        $client = $request->get('client_id')
            ? Client::query()->findOrFail($request->get('client_id'))
            : $project?->client;

        if (! $client) {
            return Response::text('Unable to determine client for this Slack link.');
        }

        if ($project && $project->client_id !== $client->id) {
            return Response::text('The provided project does not belong to the provided client.');
        }

        $previous = [
            'channel_client_id' => $channel->client_id,
            'client_slack_channel_id' => $client->slack_channel_id,
            'project_slack_channel_id' => $project?->slack_channel_id,
        ];

        $monitoringEnabled = $request->boolean('monitoring_enabled', true);

        $channel->update([
            'client_id' => $client->id,
            'classification' => 'client',
            'monitoring_enabled' => $monitoringEnabled,
            'is_monitored' => $monitoringEnabled,
        ]);

        $client->update([
            'slack_channel_id' => $channel->id,
        ]);

        if ($project) {
            $project->update([
                'slack_channel_id' => $channel->id,
            ]);
        }

        return Response::structured([
            'message' => "Linked Slack channel {$channel->channel_name} to {$client->name}".($project ? " / {$project->name}" : '').'.',
            'workspace' => [
                'id' => $workspace->workspace_id,
                'name' => $workspace->workspace_name,
            ],
            'channel' => [
                'id' => $channel->channel_id,
                'name' => $channel->channel_name,
                'classification' => $channel->classification,
                'monitoring_enabled' => $channel->monitoring_enabled,
            ],
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'slack_channel_id' => $client->slack_channel_id,
            ],
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'slack_channel_id' => $project->slack_channel_id,
            ] : null,
            'previous_links' => $previous,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->required()->description('Slack workspace/team ID'),
            'channel_id' => $schema->string()->required()->description('Slack channel ID'),
            'client_id' => $schema->integer()->description('Client to link the channel to'),
            'project_id' => $schema->integer()->description('Project to link the channel to'),
            'monitoring_enabled' => $schema->boolean()->description('Whether monitoring should be enabled for the channel'),
        ];
    }
}
