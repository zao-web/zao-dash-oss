<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Services\GitHub\GitHubApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateGitHubIssueTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'create-github-issue';

    protected string $title = 'Create GitHub Issue';

    protected string $description = 'Create a GitHub issue through Zao for a repository connected by GitHub App, then store it in Zao.';

    public function __construct(
        protected GitHubApiService $github
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'repo_id' => 'required_without:repo_full_name|integer|exists:github_repos,id',
            'repo_full_name' => 'required_without:repo_id|string',
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
            'labels' => 'nullable|array',
            'labels.*' => 'string',
            'sync_to_task' => 'nullable|boolean',
        ]);

        $repo = $this->resolveGitHubRepo($request->get('repo_id'), $request->get('repo_full_name'));

        if (! $repo->installation_id) {
            return Response::structured([
                'success' => false,
                'message' => "Repo {$repo->full_name} is linked in Zao but is not attached to a GitHub App installation, so Zao cannot create an issue there.",
                'repo' => $this->repoPayload($repo->loadMissing(['client', 'project', 'installation'])),
            ]);
        }

        $issueData = $this->github->createIssue(
            $repo,
            $request->get('title'),
            $request->get('body', ''),
            $request->get('labels', [])
        );

        $issue = $this->github->storeIssue($repo, $issueData);

        if ($request->get('sync_to_task', true)) {
            $this->github->syncIssueToTask($issue);
            $issue->refresh();
        }

        return Response::structured([
            'success' => true,
            'message' => "Created GitHub issue #{$issue->issue_number} in {$repo->full_name}.",
            'issue' => $this->issuePayload($issue->loadMissing('repo')),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repo_id' => $schema->integer()->description('Zao GitHub repo ID'),
            'repo_full_name' => $schema->string()->description('GitHub repo full name, e.g. owner/repo'),
            'title' => $schema->string()->required()->description('Issue title'),
            'body' => $schema->string()->description('Issue body'),
            'labels' => $schema->array()->description('GitHub labels to apply'),
            'sync_to_task' => $schema->boolean()->description('Also create/update the linked Zao task (default: true)'),
        ];
    }
}
