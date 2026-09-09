<?php

namespace App\Mcp\Tools;

use App\Jobs\SyncClickUpJob;
use App\Jobs\SyncGitHubJob;
use App\Jobs\SyncHarvestJob;
use App\Jobs\SyncWordPressJob;
use App\Models\GitHubRepo;
use App\Models\HarvestCredential;
use App\Models\PmConnection;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\WordPressSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RunChannelIntegrationActionTool extends Tool
{
    protected string $name = 'run-channel-integration-action';

    protected string $title = 'Run Channel Integration Action';

    protected string $description = 'Run a safe integration sync action for the client/project linked to the current Slack channel.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'workspace_id' => 'required|string',
            'channel_id' => 'required|string',
            'action' => 'required|string|in:sync-github,sync-clickup,sync-harvest,sync-wordpress,sync-all',
        ]);

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $request->get('workspace_id'))
            ->firstOrFail();

        $channel = SlackChannel::query()
            ->with(['client', 'project'])
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $request->get('channel_id'))
            ->firstOrFail();

        $action = $request->get('action');

        return Response::structured(match ($action) {
            'sync-github' => $this->syncGitHub($channel),
            'sync-clickup' => $this->syncClickUp($channel),
            'sync-harvest' => $this->syncHarvest($channel),
            'sync-wordpress' => $this->syncWordPress($channel),
            'sync-all' => $this->syncAll($channel),
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->required()->description('Slack workspace/team ID'),
            'channel_id' => $schema->string()->required()->description('Slack channel ID'),
            'action' => $schema->string()
                ->required()
                ->enum(['sync-github', 'sync-clickup', 'sync-harvest', 'sync-wordpress', 'sync-all'])
                ->description('Integration action to run for the linked channel context'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncGitHub(SlackChannel $channel): array
    {
        $project = $channel->project;
        $client = $channel->client;

        $repos = GitHubRepo::query()
            ->when(
                $project,
                fn ($query) => $query->where('project_id', $project->id),
                fn ($query) => $query->where('client_id', $client?->id)
            )
            ->whereNotNull('installation_id')
            ->get();

        if ($repos->isEmpty()) {
            return [
                'success' => false,
                'action' => 'sync-github',
                'message' => 'No linked GitHub repositories were found for this Slack channel.',
            ];
        }

        foreach ($repos as $repo) {
            SyncGitHubJob::dispatch($repo->installation_id, $repo->id)->onQueue('sync');
        }

        return [
            'success' => true,
            'action' => 'sync-github',
            'message' => "Queued GitHub sync for {$repos->count()} repo(s).",
            'queued' => [
                'repos' => $repos->map(fn (GitHubRepo $repo) => [
                    'id' => $repo->id,
                    'name' => $repo->full_name,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncClickUp(SlackChannel $channel): array
    {
        $client = $channel->client;

        if (! $client) {
            return [
                'success' => false,
                'action' => 'sync-clickup',
                'message' => 'This Slack channel is not linked to a client yet, so ClickUp cannot be resolved.',
            ];
        }

        $connections = PmConnection::query()
            ->where('client_id', $client->id)
            ->where('platform', PmConnection::PLATFORM_CLICKUP)
            ->where('is_active', true)
            ->get();

        if ($connections->isEmpty()) {
            return [
                'success' => false,
                'action' => 'sync-clickup',
                'message' => 'No active ClickUp connections were found for this client.',
            ];
        }

        foreach ($connections as $connection) {
            SyncClickUpJob::dispatch($connection->id)->onQueue('sync');
        }

        return [
            'success' => true,
            'action' => 'sync-clickup',
            'message' => "Queued ClickUp sync for {$connections->count()} connection(s).",
            'queued' => [
                'connections' => $connections->map(fn (PmConnection $connection) => [
                    'id' => $connection->id,
                    'workspace_name' => $connection->workspace_name,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncHarvest(SlackChannel $channel): array
    {
        $credentials = HarvestCredential::query()
            ->where('is_active', true)
            ->get();

        if ($credentials->isEmpty()) {
            return [
                'success' => false,
                'action' => 'sync-harvest',
                'message' => 'No active Harvest credentials were found.',
            ];
        }

        foreach ($credentials as $credential) {
            SyncHarvestJob::dispatch($credential->id)->onQueue('sync');
        }

        $projectName = $channel->project?->name;

        return [
            'success' => true,
            'action' => 'sync-harvest',
            'message' => 'Queued Harvest sync for all active credentials.'.($projectName ? " Context project: {$projectName}." : ''),
            'queued' => [
                'credentials' => $credentials->map(fn (HarvestCredential $credential) => [
                    'id' => $credential->id,
                    'account_name' => $credential->account_name,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncWordPress(SlackChannel $channel): array
    {
        $client = $channel->client;

        if (! $client) {
            return [
                'success' => false,
                'action' => 'sync-wordpress',
                'message' => 'This Slack channel is not linked to a client yet, so WordPress sites cannot be resolved.',
            ];
        }

        $sites = WordPressSite::query()
            ->where('client_id', $client->id)
            ->get();

        if ($sites->isEmpty()) {
            return [
                'success' => false,
                'action' => 'sync-wordpress',
                'message' => 'No WordPress sites were found for this client.',
            ];
        }

        foreach ($sites as $site) {
            SyncWordPressJob::dispatch($site->id)->onQueue('sync');
        }

        return [
            'success' => true,
            'action' => 'sync-wordpress',
            'message' => "Queued WordPress sync for {$sites->count()} site(s).",
            'queued' => [
                'sites' => $sites->map(fn (WordPressSite $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'url' => $site->url,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncAll(SlackChannel $channel): array
    {
        $results = [
            $this->syncGitHub($channel),
            $this->syncClickUp($channel),
            $this->syncHarvest($channel),
            $this->syncWordPress($channel),
        ];

        return [
            'success' => collect($results)->contains(fn (array $result) => $result['success'] ?? false),
            'action' => 'sync-all',
            'message' => 'Queued all available integration sync actions for this Slack channel.',
            'results' => $results,
        ];
    }
}
