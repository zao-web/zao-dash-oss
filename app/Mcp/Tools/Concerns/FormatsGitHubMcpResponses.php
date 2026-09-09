<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use Illuminate\Database\Eloquent\Builder;

trait FormatsGitHubMcpResponses
{
    protected function resolveGitHubRepo(?int $id, ?string $fullName): GitHubRepo
    {
        return GitHubRepo::query()
            ->when($id, fn (Builder $query) => $query->where('id', $id))
            ->when(! $id && $fullName, fn (Builder $query) => $query->where('full_name', $fullName))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function repoPayload(GitHubRepo $repo): array
    {
        return [
            'id' => $repo->id,
            'repo_id' => $repo->repo_id,
            'full_name' => $repo->full_name,
            'owner' => $repo->owner,
            'name' => $repo->name,
            'url' => $repo->url,
            'is_private' => $repo->is_private,
            'is_archived' => $repo->is_archived,
            'default_branch' => $repo->default_branch,
            'monitoring_enabled' => $repo->monitoring_enabled,
            'client' => $repo->client ? [
                'id' => $repo->client->id,
                'name' => $repo->client->name,
                'slug' => $repo->client->slug,
            ] : null,
            'project' => $repo->project ? [
                'id' => $repo->project->id,
                'name' => $repo->project->name,
                'slug' => $repo->project->slug,
            ] : null,
            'installation' => $repo->installation ? [
                'id' => $repo->installation->id,
                'account_login' => $repo->installation->account_login,
                'account_type' => $repo->installation->account_type,
                'last_synced_at' => $repo->installation->last_synced_at?->toIso8601String(),
            ] : null,
            'open_issues_count' => $repo->open_issues_count ?? $repo->openIssues()->count(),
            'open_pull_requests_count' => $repo->open_pull_requests_count ?? $repo->openPullRequests()->count(),
            'pushed_at' => $repo->pushed_at?->toIso8601String(),
            'issues_synced_at' => $repo->issues_synced_at?->toIso8601String(),
            'prs_synced_at' => $repo->prs_synced_at?->toIso8601String(),
            'created_at' => $repo->created_at?->toIso8601String(),
            'updated_at' => $repo->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function issuePayload(GitHubIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'repo_id' => $issue->repo_id,
            'repo_name' => $issue->repo?->full_name,
            'issue_number' => $issue->issue_number,
            'title' => $issue->title,
            'body' => $issue->body,
            'state' => $issue->state,
            'labels' => $issue->labels ?? [],
            'assignees' => $issue->assignees ?? [],
            'task_id' => $issue->task_id,
            'agent_run_id' => $issue->agent_run_id,
            'url' => $issue->repo ? $issue->url : null,
            'closed_at' => $issue->closed_at?->toIso8601String(),
            'created_at' => $issue->created_at?->toIso8601String(),
            'updated_at' => $issue->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function pullRequestPayload(GitHubPullRequest $pullRequest): array
    {
        return [
            'id' => $pullRequest->id,
            'repo_id' => $pullRequest->repo_id,
            'repo_name' => $pullRequest->repo?->full_name,
            'pr_number' => $pullRequest->pr_number,
            'title' => $pullRequest->title,
            'body' => $pullRequest->body,
            'state' => $pullRequest->state,
            'author' => $pullRequest->author,
            'base_branch' => $pullRequest->base_branch,
            'head_branch' => $pullRequest->head_branch,
            'reviewers' => $pullRequest->reviewers ?? [],
            'approval_status' => $pullRequest->approval_status,
            'needs_approval' => $pullRequest->needsApproval(),
            'checks_passed' => $pullRequest->checks_passed,
            'approval_request_id' => $pullRequest->approval_request_id,
            'qa_agent_run_id' => $pullRequest->qa_agent_run_id,
            'url' => $pullRequest->repo ? $pullRequest->url : null,
            'merged_at' => $pullRequest->merged_at?->toIso8601String(),
            'created_at' => $pullRequest->created_at?->toIso8601String(),
            'updated_at' => $pullRequest->updated_at?->toIso8601String(),
        ];
    }

    protected function boundedLimit(mixed $limit, int $default = 50, int $max = 100): int
    {
        $limit = (int) ($limit ?: $default);

        return max(1, min($max, $limit));
    }
}
