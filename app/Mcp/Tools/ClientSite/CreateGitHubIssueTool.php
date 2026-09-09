<?php

namespace App\Mcp\Tools\ClientSite;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\Client;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Client-scoped create-github-issue. Same name as the internal tool so the
 * client widget speaks one vocabulary, but the repo is resolved ONLY within the
 * calling client's own repositories — a request that names another client's repo
 * is rejected (404, indistinguishable from "no such repo"), never created.
 *
 * Always syncs to a task, so the request lands in the client's retainer report;
 * the resulting PR is auto-logged by the client activity feed.
 */
class CreateGitHubIssueTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'create-github-issue';

    protected string $title = 'Create GitHub Issue (scoped)';

    protected string $description = 'File a GitHub issue for one of THIS client\'s connected repositories. Syncs to a Zao task for the retainer report.';

    public function __construct(
        protected GitHubApiService $github
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'repo_full_name' => 'required|string',
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
            'labels' => 'nullable|array',
            'labels.*' => 'string',
        ]);

        /** @var Client $client */
        $client = $request->user();

        // Resolve the repo WITHIN the client's own repos only. No cross-client
        // lookup is possible: the where() is bound to the token's client_id.
        $repo = GitHubRepo::query()
            ->where('client_id', $client->id)
            ->where('full_name', $request->get('repo_full_name'))
            ->first();

        if (! $repo) {
            return Response::structured([
                'success' => false,
                'message' => "No connected repository '{$request->get('repo_full_name')}' for this client.",
            ]);
        }

        if (! $repo->installation_id) {
            return Response::structured([
                'success' => false,
                'message' => "Repo {$repo->full_name} is linked but has no GitHub App installation, so Zao cannot create an issue there.",
            ]);
        }

        $issueData = $this->github->createIssue(
            $repo,
            $request->get('title'),
            $request->get('body', ''),
            $request->get('labels', [])
        );

        $issue = $this->github->storeIssue($repo, $issueData);
        $this->github->syncIssueToTask($issue); // → task → retainer report
        $issue->refresh();

        return Response::structured([
            'success' => true,
            'message' => "Created GitHub issue #{$issue->issue_number} in {$repo->full_name}.",
            'issue' => $this->issuePayload($issue->loadMissing('repo')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_full_name' => $schema->string()->required()->description("One of this client's repos, e.g. owner/repo"),
            'title' => $schema->string()->required()->description('Issue title'),
            'body' => $schema->string()->description('Issue body'),
            'labels' => $schema->array()->description('GitHub labels to apply'),
        ];
    }
}
