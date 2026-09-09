<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListGitHubReposTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'list-github-repos';

    protected string $title = 'List GitHub Repositories';

    protected string $description = 'List GitHub repositories known to Zao, with optional client/project/status filtering.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = $this->boundedLimit($request->get('limit'));

        $query = GitHubRepo::query()
            ->with(['client:id,name,slug', 'project:id,name,slug', 'installation:id,account_login,account_type,last_synced_at'])
            ->withCount([
                'openIssues as open_issues_count',
                'openPullRequests as open_pull_requests_count',
            ]);

        if ($request->get('client_id')) {
            $query->where('client_id', $request->get('client_id'));
        }

        if ($request->get('project_id')) {
            $query->where('project_id', $request->get('project_id'));
        }

        if ($request->has('monitoring_enabled')) {
            $query->where('monitoring_enabled', (bool) $request->get('monitoring_enabled'));
        }

        if ($request->has('linked')) {
            $request->get('linked')
                ? $query->whereNotNull('project_id')
                : $query->whereNull('project_id');
        }

        if ($request->has('archived')) {
            $query->where('is_archived', (bool) $request->get('archived'));
        }

        if ($search = $request->get('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('full_name', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('owner', 'like', "%{$search}%");
            });
        }

        $repos = $query->orderByDesc('pushed_at')
            ->orderBy('full_name')
            ->limit($limit)
            ->get()
            ->map(fn (GitHubRepo $repo) => $this->repoPayload($repo));

        return Response::structured([
            'repos' => $repos,
            'total' => $repos->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('Filter by Zao client ID'),
            'project_id' => $schema->integer()->description('Filter by Zao project ID'),
            'monitoring_enabled' => $schema->boolean()->description('Filter by GitHub monitoring status'),
            'linked' => $schema->boolean()->description('true for repos linked to a project, false for unlinked repos'),
            'archived' => $schema->boolean()->description('Filter by GitHub archived status'),
            'search' => $schema->string()->description('Search repo owner, name, or full name'),
            'limit' => $schema->integer()->description('Maximum repositories to return (default: 50, max: 100)'),
        ];
    }
}
