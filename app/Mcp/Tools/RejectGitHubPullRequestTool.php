<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubPullRequest;
use App\Services\GitHub\GitHubApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RejectGitHubPullRequestTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'reject-github-pull-request';

    protected string $title = 'Reject GitHub Pull Request';

    protected string $description = 'Reject a GitHub pull request in Zao, with an optional GitHub comment.';

    public function __construct(
        protected GitHubApiService $github
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|integer|exists:github_pull_requests,id',
            'reason' => 'nullable|string',
            'comment' => 'nullable|string',
        ]);

        $pullRequest = GitHubPullRequest::query()
            ->with(['repo.client:id,name,slug', 'repo.project:id,name,slug', 'repo.installation'])
            ->findOrFail($request->get('id'));

        if ($request->get('comment') && ! $pullRequest->repo?->installation_id) {
            return Response::structured([
                'success' => false,
                'message' => "PR #{$pullRequest->pr_number} is linked in Zao but its repo is not attached to a GitHub App installation, so Zao cannot comment on GitHub.",
                'pull_request' => $this->pullRequestPayload($pullRequest),
            ]);
        }

        $pullRequest->update(['approval_status' => 'rejected']);

        if ($comment = $request->get('comment')) {
            $this->github->addPrComment($pullRequest->repo, $pullRequest->pr_number, $comment);
        }

        return Response::structured([
            'success' => true,
            'message' => "Rejected PR #{$pullRequest->pr_number} in Zao.",
            'reason' => $request->get('reason'),
            'pull_request' => $this->pullRequestPayload($pullRequest->fresh()->loadMissing('repo')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Zao GitHub pull request ID'),
            'reason' => $schema->string()->description('Internal reason for rejection'),
            'comment' => $schema->string()->description('Optional GitHub comment to add to the PR while rejecting'),
        ];
    }
}
