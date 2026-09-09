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

class ApproveGitHubPullRequestTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'approve-github-pull-request';

    protected string $title = 'Approve GitHub Pull Request';

    protected string $description = 'Approve a GitHub pull request in Zao. Optionally merge an eligible develop PR only when explicitly requested.';

    public function __construct(
        protected GitHubApiService $github
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|integer|exists:github_pull_requests,id',
            'note' => 'nullable|string',
            'comment' => 'nullable|string',
            'merge_if_eligible' => 'nullable|boolean',
        ]);

        $pullRequest = GitHubPullRequest::query()
            ->with(['repo.client:id,name,slug', 'repo.project:id,name,slug', 'repo.installation'])
            ->findOrFail($request->get('id'));

        $needsGitHubWrite = $request->get('comment') || $request->get('merge_if_eligible');
        if ($needsGitHubWrite && ! $pullRequest->repo?->installation_id) {
            return Response::structured([
                'success' => false,
                'message' => "PR #{$pullRequest->pr_number} is linked in Zao but its repo is not attached to a GitHub App installation, so Zao cannot write back to GitHub.",
                'pull_request' => $this->pullRequestPayload($pullRequest),
            ]);
        }

        $pullRequest->update(['approval_status' => 'approved']);
        $message = "Approved PR #{$pullRequest->pr_number} in Zao.";

        if ($comment = $request->get('comment')) {
            $this->github->addPrComment($pullRequest->repo, $pullRequest->pr_number, $comment);
        }

        $merged = false;
        if ($request->get('merge_if_eligible')) {
            if (! $pullRequest->targetsDevelop() || ! $pullRequest->checks_passed) {
                return Response::structured([
                    'success' => false,
                    'message' => 'PR was approved in Zao, but it was not merged because it does not target develop/dev/development with passing checks.',
                    'pull_request' => $this->pullRequestPayload($pullRequest->fresh()->loadMissing('repo')),
                ]);
            }

            $this->github->mergePullRequest($pullRequest->repo, $pullRequest->pr_number);
            $pullRequest->update([
                'state' => 'merged',
                'merged_at' => now(),
            ]);
            $merged = true;
            $message = "Approved and merged PR #{$pullRequest->pr_number}.";
        }

        return Response::structured([
            'success' => true,
            'merged' => $merged,
            'message' => $message,
            'note' => $request->get('note'),
            'pull_request' => $this->pullRequestPayload($pullRequest->fresh()->loadMissing('repo')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Zao GitHub pull request ID'),
            'note' => $schema->string()->description('Internal context for why this PR is being approved'),
            'comment' => $schema->string()->description('Optional GitHub comment to add to the PR while approving'),
            'merge_if_eligible' => $schema->boolean()->description('Explicitly merge only if the PR targets develop/dev/development and checks have passed'),
        ];
    }
}
