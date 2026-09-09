<?php

namespace App\Mcp\Tools;

use App\Models\AgentRun;
use App\Models\GitHubRepo;
use App\Models\PmConnection;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\SpinupWpSite;
use App\Models\WordPressSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetChannelOperationsContextTool extends Tool
{
    protected string $name = 'get-channel-operations-context';

    protected string $title = 'Get Channel Operations Context';

    protected string $description = 'Get the current Slack channel operating context, including linked client, project, integrations, and recent operational state.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'workspace_id' => 'required|string',
            'channel_id' => 'required|string',
        ]);

        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $request->get('workspace_id'))
            ->firstOrFail();

        $channel = SlackChannel::query()
            ->with([
                'client.contacts',
                'client.pmConnections',
                'project.tasks',
            ])
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $request->get('channel_id'))
            ->firstOrFail();

        $client = $channel->client;
        $project = $channel->project ?? $client?->projects()->where('status', 'active')->first();

        $githubRepos = GitHubRepo::query()
            ->with('installation')
            ->when(
                $project,
                fn ($query) => $query->where('project_id', $project->id),
                fn ($query) => $query->where('client_id', $client?->id)
            )
            ->orderByDesc('pushed_at')
            ->limit(10)
            ->get();

        $pmConnections = $client
            ? PmConnection::query()
                ->where('client_id', $client->id)
                ->orderBy('platform')
                ->get()
            : collect();

        $wordPressSites = $client
            ? WordPressSite::query()
                ->where('client_id', $client->id)
                ->orderBy('name')
                ->get()
            : collect();

        $spinupSites = $wordPressSites->isNotEmpty()
            ? SpinupWpSite::query()
                ->with('server')
                ->whereIn('wordpress_site_id', $wordPressSites->pluck('id'))
                ->orderBy('domain')
                ->get()
            : collect();

        $recentRuns = $project
            ? AgentRun::query()
                ->with('agent')
                ->where('project_id', $project->id)
                ->latest()
                ->limit(5)
                ->get()
            : collect();

        $taskSummary = $project
            ? [
                'total' => $project->tasks()->count(),
                'pending' => $project->tasks()->where('status', 'pending')->count(),
                'in_progress' => $project->tasks()->where('status', 'in_progress')->count(),
                'review' => $project->tasks()->where('status', 'review')->count(),
                'completed' => $project->tasks()->where('status', 'completed')->count(),
            ]
            : null;

        return Response::structured([
            'workspace' => [
                'id' => $workspace->workspace_id,
                'name' => $workspace->workspace_name,
                'bot_user_id' => $workspace->bot_user_id,
                'is_primary' => (bool) $workspace->is_primary,
            ],
            'channel' => [
                'id' => $channel->channel_id,
                'name' => $channel->channel_name,
                'classification' => $channel->classification,
                'monitoring_enabled' => $channel->monitoring_enabled,
                'is_shared' => $channel->is_shared,
                'is_private' => $channel->is_private,
                'is_dm' => $channel->is_dm,
            ],
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'status' => $client->status,
                'health_score' => $client->health_score,
                'website' => $client->website,
                'slack_channel_id' => $client->slack_channel_id,
                'contacts' => $client->contacts->map(fn ($contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->email,
                    'role' => $contact->role,
                    'is_primary' => $contact->is_primary,
                ])->values()->all(),
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'type' => $project->type,
                'github_repo' => $project->github_repo,
                'notion_page_id' => $project->notion_page_id,
                'slack_channel_id' => $project->slack_channel_id,
                'task_summary' => $taskSummary,
                'open_tasks' => $project->tasks()
                    ->whereIn('status', ['pending', 'in_progress', 'review'])
                    ->orderByRaw("case when status = 'in_progress' then 0 when status = 'review' then 1 else 2 end")
                    ->limit(8)
                    ->get(['id', 'title', 'status', 'priority'])
                    ->map(fn ($task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'status' => $task->status,
                        'priority' => $task->priority,
                    ])
                    ->values()
                    ->all(),
            ] : null,
            'integrations' => [
                'github_repos' => $githubRepos->map(fn ($repo) => [
                    'id' => $repo->id,
                    'name' => $repo->name,
                    'full_name' => $repo->full_name,
                    'url' => $repo->url,
                    'is_private' => $repo->is_private,
                    'monitoring_enabled' => $repo->monitoring_enabled,
                    'pushed_at' => $repo->pushed_at?->toIso8601String(),
                    'installation' => $repo->installation ? [
                        'id' => $repo->installation->id,
                        'account_login' => $repo->installation->account_login,
                        'account_type' => $repo->installation->account_type,
                    ] : null,
                ])->values()->all(),
                'pm_connections' => $pmConnections->map(fn ($connection) => [
                    'id' => $connection->id,
                    'platform' => $connection->platform,
                    'workspace_name' => $connection->workspace_name,
                    'workspace_id' => $connection->workspace_id,
                    'is_active' => $connection->is_active,
                    'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                ])->values()->all(),
                'wordpress_sites' => $wordPressSites->map(fn ($site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'url' => $site->url,
                    'mcp_enabled' => $site->mcp_enabled,
                    'is_primary' => $site->is_primary,
                    'last_synced_at' => $site->last_synced_at?->toIso8601String(),
                ])->values()->all(),
                'spinup_sites' => $spinupSites->map(fn ($site) => [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'status' => $site->status,
                    'https_enabled' => $site->https_enabled,
                    'page_cache_enabled' => $site->page_cache_enabled,
                    'last_synced_at' => $site->last_synced_at?->toIso8601String(),
                    'server' => $site->server ? [
                        'id' => $site->server->id,
                        'name' => $site->server->name,
                    ] : null,
                ])->values()->all(),
                'harvest_project' => $project?->harvestProject ? [
                    'id' => $project->harvestProject->id,
                    'harvest_id' => $project->harvestProject->harvest_id,
                    'name' => $project->harvestProject->name,
                    'is_active' => $project->harvestProject->is_active,
                    'budget' => $project->harvestProject->budget,
                    'budget_used_percent' => $project->harvestProject->budget_used_percent,
                ] : null,
            ],
            'recent_agent_runs' => $recentRuns->map(fn ($run) => [
                'id' => $run->id,
                'agent' => $run->agent?->name,
                'status' => $run->status,
                'task' => $run->task,
                'created_at' => $run->created_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace_id' => $schema->string()->required()->description('Slack workspace/team ID'),
            'channel_id' => $schema->string()->required()->description('Slack channel ID'),
        ];
    }
}
