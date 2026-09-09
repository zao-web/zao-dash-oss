<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetGitHubRepoTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'get-github-repo';

    protected string $title = 'Get GitHub Repository';

    protected string $description = 'Get a GitHub repository known to Zao, including linked client/project context and recent issues/PRs.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:full_name|integer|exists:github_repos,id',
            'full_name' => 'required_without:id|string',
        ]);

        $repo = $this->resolveGitHubRepo($request->get('id'), $request->get('full_name'))
            ->load(['client:id,name,slug', 'project:id,name,slug', 'installation:id,account_login,account_type,last_synced_at'])
            ->loadCount([
                'openIssues as open_issues_count',
                'openPullRequests as open_pull_requests_count',
            ]);

        $recentIssues = $repo->issues()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($issue) => $this->issuePayload($issue->loadMissing('repo')));

        $recentPullRequests = $repo->pullRequests()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($pullRequest) => $this->pullRequestPayload($pullRequest->loadMissing('repo')));

        return Response::structured([
            'repo' => $this->repoPayload($repo),
            'recent_issues' => $recentIssues,
            'recent_pull_requests' => $recentPullRequests,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Zao GitHub repo ID'),
            'full_name' => $schema->string()->description('GitHub repo full name, e.g. owner/repo'),
        ];
    }
}
