<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubPullRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetGitHubPullRequestTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'get-github-pull-request';

    protected string $title = 'Get GitHub Pull Request';

    protected string $description = 'Get one GitHub pull request synced into Zao.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without_all:repo_id,repo_full_name|integer|exists:github_pull_requests,id',
            'repo_id' => 'required_without_all:id,repo_full_name|integer|exists:github_repos,id',
            'repo_full_name' => 'required_without_all:id,repo_id|string',
            'pr_number' => 'required_without:id|integer',
        ]);

        $query = GitHubPullRequest::query()
            ->with(['repo.client:id,name,slug', 'repo.project:id,name,slug', 'approvalRequest']);

        if ($request->get('id')) {
            $pullRequest = $query->findOrFail($request->get('id'));
        } else {
            $query->where('pr_number', $request->get('pr_number'));

            if ($request->get('repo_id')) {
                $query->where('repo_id', $request->get('repo_id'));
            } else {
                $query->whereHas('repo', fn ($query) => $query->where('full_name', $request->get('repo_full_name')));
            }

            $pullRequest = $query->firstOrFail();
        }

        return Response::structured([
            'pull_request' => $this->pullRequestPayload($pullRequest),
            'repo' => $pullRequest->repo ? $this->repoPayload($pullRequest->repo) : null,
            'approval_request' => $pullRequest->approvalRequest ? [
                'id' => $pullRequest->approvalRequest->id,
                'status' => $pullRequest->approvalRequest->status,
                'risk_level' => $pullRequest->approvalRequest->risk_level,
                'description' => $pullRequest->approvalRequest->description,
            ] : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Zao GitHub pull request ID'),
            'repo_id' => $schema->integer()->description('Zao GitHub repo ID, used with pr_number'),
            'repo_full_name' => $schema->string()->description('GitHub repo full name, used with pr_number'),
            'pr_number' => $schema->integer()->description('GitHub pull request number'),
        ];
    }
}
