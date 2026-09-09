<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubIssue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListGitHubIssuesTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'list-github-issues';

    protected string $title = 'List GitHub Issues';

    protected string $description = 'List GitHub issues synced into Zao, with repo/client/project filters.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = $this->boundedLimit($request->get('limit'));

        $query = GitHubIssue::query()
            ->with(['repo.client:id,name,slug', 'repo.project:id,name,slug']);

        if ($request->get('repo_id')) {
            $query->where('repo_id', $request->get('repo_id'));
        }

        if ($request->get('repo_full_name')) {
            $query->whereHas('repo', fn ($query) => $query->where('full_name', $request->get('repo_full_name')));
        }

        if ($request->get('client_id')) {
            $query->whereHas('repo', fn ($query) => $query->where('client_id', $request->get('client_id')));
        }

        if ($request->get('project_id')) {
            $query->whereHas('repo', fn ($query) => $query->where('project_id', $request->get('project_id')));
        }

        if ($state = $request->get('state')) {
            $query->where('state', $state);
        }

        if ($request->get('unlinked_to_task')) {
            $query->whereNull('task_id');
        }

        if ($search = $request->get('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $issues = $query->latest()
            ->limit($limit)
            ->get()
            ->map(fn (GitHubIssue $issue) => $this->issuePayload($issue));

        return Response::structured([
            'issues' => $issues,
            'total' => $issues->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->integer()->description('Filter by Zao GitHub repo ID'),
            'repo_full_name' => $schema->string()->description('Filter by GitHub repo full name, e.g. owner/repo'),
            'client_id' => $schema->integer()->description('Filter by linked Zao client ID'),
            'project_id' => $schema->integer()->description('Filter by linked Zao project ID'),
            'state' => $schema->string()->enum(['open', 'closed'])->description('Filter by issue state'),
            'unlinked_to_task' => $schema->boolean()->description('Only show issues not linked to Zao tasks'),
            'search' => $schema->string()->description('Search issue title/body'),
            'limit' => $schema->integer()->description('Maximum issues to return (default: 50, max: 100)'),
        ];
    }
}
