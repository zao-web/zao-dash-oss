<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsGitHubMcpResponses;
use App\Models\GitHubRepo;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class LinkGitHubRepoToProjectTool extends Tool
{
    use FormatsGitHubMcpResponses;

    protected string $name = 'link-github-repo-to-project';

    protected string $title = 'Link GitHub Repository to Project';

    protected string $description = 'Link a GitHub repository record to a Zao project and its client context.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'repo_id' => 'required_without:full_name|integer|exists:github_repos,id',
            'full_name' => ['required_without:repo_id', 'string', 'regex:/^[^\/]+\/[^\/]+$/'],
            'create_if_missing' => 'nullable|boolean',
            'monitoring_enabled' => 'nullable|boolean',
        ]);

        $project = Project::with('client')->findOrFail($request->get('project_id'));
        $repo = null;

        if ($request->get('repo_id')) {
            $repo = GitHubRepo::findOrFail($request->get('repo_id'));
        } elseif ($request->get('full_name')) {
            $repo = GitHubRepo::where('full_name', $request->get('full_name'))->first();
        }

        if (! $repo && ! $request->get('create_if_missing', true)) {
            return Response::structured([
                'success' => false,
                'message' => "Repo {$request->get('full_name')} is not known to Zao.",
            ]);
        }

        if (! $repo) {
            [$owner, $name] = explode('/', $request->get('full_name'), 2);
            $repo = GitHubRepo::create([
                'owner' => $owner,
                'name' => $name,
                'full_name' => $request->get('full_name'),
                'is_private' => true,
                'is_archived' => false,
                'default_branch' => 'main',
                'monitoring_enabled' => (bool) $request->get('monitoring_enabled', true),
            ]);
        }

        $repo->update([
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'monitoring_enabled' => $request->has('monitoring_enabled')
                ? (bool) $request->get('monitoring_enabled')
                : $repo->monitoring_enabled,
        ]);

        return Response::structured([
            'success' => true,
            'message' => "Linked {$repo->full_name} to project {$project->name}.",
            'repo' => $this->repoPayload($repo->fresh()->loadMissing(['client', 'project', 'installation'])),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required()->description('Zao project ID'),
            'repo_id' => $schema->integer()->description('Existing Zao GitHub repo ID'),
            'full_name' => $schema->string()->description('GitHub repo full name, e.g. owner/repo'),
            'create_if_missing' => $schema->boolean()->description('Create a Zao-only repo record when full_name is unknown (default: true)'),
            'monitoring_enabled' => $schema->boolean()->description('Set GitHub monitoring for the linked repo'),
        ];
    }
}
