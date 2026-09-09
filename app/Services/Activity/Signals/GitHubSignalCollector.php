<?php

namespace App\Services\Activity\Signals;

use App\Models\Client;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Services\Reports\RetainerHealthService;
use Carbon\Carbon;

class GitHubSignalCollector
{
    public function __construct(protected RetainerHealthService $health) {}

    /**
     * Collect open GitHub PRs and issues from repos linked to the client.
     * For activity-feed purposes "still open" matters more than "recently
     * touched" — a 90-day-old open PR is still in flight. We do scope to
     * the window for ordering/ranking but include older open items.
     *
     * @return array<int, array{source_type:string, external_id:string, permalink:?string, occurred_at:Carbon, actor:?string, content:string, meta:array<string,mixed>}>
     */
    public function collect(Client $client, Carbon $since): array
    {
        $repos = $this->health->reposForClient($client->id);
        if ($repos->isEmpty()) {
            return [];
        }

        $repoIds = $repos->pluck('id')->all();
        $repoNameMap = $repos->pluck('full_name', 'id')->all();

        $signals = [];

        $prs = GitHubPullRequest::query()
            ->whereIn('repo_id', $repoIds)
            ->where('state', 'open')
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        foreach ($prs as $pr) {
            $fullName = $repoNameMap[$pr->repo_id] ?? 'unknown/unknown';
            $signals[] = [
                'source_type' => 'github_pr',
                'external_id' => $fullName.':'.$pr->pr_number,
                'permalink' => 'https://github.com/'.$fullName.'/pull/'.$pr->pr_number,
                'occurred_at' => $pr->updated_at ?? $pr->created_at,
                'actor' => $pr->author,
                'content' => trim($pr->title."\n\n".mb_substr((string) $pr->body, 0, 1000)),
                'meta' => [
                    'repo' => $fullName,
                    'pr_number' => $pr->pr_number,
                    'state' => $pr->state,
                    'checks_passed' => $pr->checks_passed,
                    'approval_status' => $pr->approval_status,
                    'base_branch' => $pr->base_branch,
                    'head_branch' => $pr->head_branch,
                ],
            ];
        }

        $issues = GitHubIssue::query()
            ->whereIn('repo_id', $repoIds)
            ->where('state', 'open')
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        foreach ($issues as $issue) {
            $fullName = $repoNameMap[$issue->repo_id] ?? 'unknown/unknown';
            $signals[] = [
                'source_type' => 'github_issue',
                'external_id' => $fullName.':i:'.$issue->issue_number,
                'permalink' => 'https://github.com/'.$fullName.'/issues/'.$issue->issue_number,
                'occurred_at' => $issue->updated_at ?? $issue->created_at,
                'actor' => is_array($issue->assignees) ? implode(', ', $issue->assignees) : null,
                'content' => trim($issue->title."\n\n".mb_substr((string) $issue->body, 0, 1000)),
                'meta' => [
                    'repo' => $fullName,
                    'issue_number' => $issue->issue_number,
                    'state' => $issue->state,
                    'labels' => $issue->labels,
                    'has_linked_task' => $issue->task_id !== null,
                ],
            ];
        }

        return $signals;
    }
}
