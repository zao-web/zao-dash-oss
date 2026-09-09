<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubPullRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListGitHubPullRequestsTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'list-github-pull-requests';

    protected string $title = 'List GitHub Pull Requests';

    protected string $description = 'List GitHub pull requests synced into Zao, with repo/client/project and approval filters.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $limit = $this->boundedLimit($request->get('limit'));

        $query = GitHubPullRequest::query()
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

        if ($approvalStatus = $request->get('approval_status')) {
            $query->where('approval_status', $approvalStatus);
        }

        if ($request->get('needs_approval')) {
            $query->whereIn('base_branch', ['main', 'master'])
                ->where('approval_status', 'pending');
        }

        if ($search = $request->get('search')) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%");
            });
        }

        $pullRequests = $query->latest()
            ->limit($limit)
            ->get()
            ->map(fn (GitHubPullRequest $pullRequest) => $this->pullRequestPayload($pullRequest));

        return Response::structured([
            'pull_requests' => $pullRequests,
            'total' => $pullRequests->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->integer()->description('Filter by Zao GitHub repo ID'),
            'repo_full_name' => $schema->string()->description('Filter by GitHub repo full name, e.g. owner/repo'),
            'client_id' => $schema->integer()->description('Filter by linked Zao client ID'),
            'project_id' => $schema->integer()->description('Filter by linked Zao project ID'),
            'state' => $schema->string()->enum(['open', 'closed', 'merged'])->description('Filter by PR state'),
            'approval_status' => $schema->string()->enum(['pending', 'approved', 'rejected'])->description('Filter by Zao approval status'),
            'needs_approval' => $schema->boolean()->description('Only show main/master PRs pending approval'),
            'search' => $schema->string()->description('Search PR title/body/author'),
            'limit' => $schema->integer()->description('Maximum pull requests to return (default: 50, max: 100)'),
        ];
    }
}
