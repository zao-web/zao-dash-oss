<?php

namespace App\Mcp\Tools;

use App\Jobs\SyncGitHubJob;
use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SyncGitHubRepoTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'sync-github-repo';

    protected string $title = 'Sync GitHub Repository';

    protected string $description = 'Queue a Zao GitHub sync for a repository or all GitHub installations.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'nullable|integer|exists:github_repos,id',
            'full_name' => 'nullable|string',
            'sync_all' => 'nullable|boolean',
        ]);

        if ($request->get('sync_all')) {
            SyncGitHubJob::dispatch()->onQueue('sync');

            return Response::structured([
                'success' => true,
                'message' => 'Queued GitHub sync for all installations.',
            ]);
        }

        if (! $request->get('id') && ! $request->get('full_name')) {
            return Response::structured([
                'success' => false,
                'message' => 'Provide id, full_name, or sync_all=true.',
            ]);
        }

        $repo = $this->resolveGitHubRepo($request->get('id'), $request->get('full_name'));

        if (! $repo->installation_id) {
            return Response::structured([
                'success' => false,
                'message' => "Repo {$repo->full_name} is linked in Zao but is not attached to a GitHub App installation, so it cannot be synced by the GitHub App.",
                'repo' => $this->repoPayload($repo->loadMissing(['client', 'project', 'installation'])),
            ]);
        }

        SyncGitHubJob::dispatch($repo->installation_id, $repo->id)->onQueue('sync');

        return Response::structured([
            'success' => true,
            'message' => "Queued GitHub sync for {$repo->full_name}.",
            'repo' => $this->repoPayload($repo->loadMissing(['client', 'project', 'installation'])),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Zao GitHub repo ID'),
            'full_name' => $schema->string()->description('GitHub repo full name, e.g. owner/repo'),
            'sync_all' => $schema->boolean()->description('Queue a sync for all GitHub installations'),
        ];
    }
}
