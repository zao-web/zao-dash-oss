<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\GitHubInstallation;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGitHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $installationId = null,
        public ?int $repoId = null
    ) {}

    public function handle(GitHubAppService $appService, GitHubApiService $apiService): void
    {
        $installations = $this->installationId
            ? GitHubInstallation::where('id', $this->installationId)->get()
            : GitHubInstallation::all();

        foreach ($installations as $installation) {
            try {
                $this->syncInstallation($installation, $appService, $apiService);
            } catch (\Exception $e) {
                Log::error('GitHub sync failed', [
                    'installation_id' => $installation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function syncInstallation(
        GitHubInstallation $installation,
        GitHubAppService $appService,
        GitHubApiService $apiService
    ): void {
        $this->initSyncTracking($installation);

        try {
            Log::info('Syncing GitHub installation', ['account' => $installation->account_login]);

            // Sync repositories using the app service
            $repoCount = $appService->syncRepos($installation);
            Log::info('Synced GitHub repos', ['count' => $repoCount]);
            $this->updateSyncProgress(20, 'repositories');

            // Sync issues and PRs for actively monitored repos (active in last 6 months)
            $repos = $this->repoId
                ? GitHubRepo::where('id', $this->repoId)->get()
                : $installation->repos()->activelyMonitored(6)->get();

            Log::info('Syncing issues/PRs for active repos', [
                'installation_id' => $installation->id,
                'active_repos' => $repos->count(),
                'total_repos' => $installation->repos()->count(),
            ]);

            $totalRepos = $repos->count();
            foreach ($repos as $index => $repo) {
                $this->syncRepoIssues($repo, $apiService);
                $this->syncRepoPullRequests($repo, $apiService);
                $progress = 20 + (int) (($index + 1) / max(1, $totalRepos) * 75);
                $this->updateSyncProgress($progress, "repo: {$repo->name}");
            }

            $installation->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncRepoIssues(GitHubRepo $repo, GitHubApiService $apiService): void
    {
        Log::info('Syncing issues for repo', ['repo' => $repo->full_name]);

        try {
            $since = $repo->issues_synced_at
                ? \Carbon\Carbon::parse($repo->issues_synced_at)
                : now()->subDays(30);
            $issues = $apiService->listIssues($repo, [
                'state' => 'all',
                'since' => $since->toIso8601String(),
            ]);

            foreach ($issues as $issueData) {
                // Skip pull requests (they come through issues API too)
                if (isset($issueData['pull_request'])) {
                    continue;
                }

                GitHubIssue::updateOrCreate(
                    [
                        'repo_id' => $repo->id,
                        'github_id' => $issueData['id'],
                    ],
                    [
                        'number' => $issueData['number'],
                        'title' => $issueData['title'],
                        'body' => $issueData['body'],
                        'state' => $issueData['state'],
                        'url' => $issueData['html_url'],
                        'author' => $issueData['user']['login'] ?? null,
                        'author_avatar' => $issueData['user']['avatar_url'] ?? null,
                        'assignees' => collect($issueData['assignees'] ?? [])->pluck('login')->toArray(),
                        'labels' => collect($issueData['labels'] ?? [])->pluck('name')->toArray(),
                        'milestone' => $issueData['milestone']['title'] ?? null,
                        'comments_count' => $issueData['comments'] ?? 0,
                        'created_at_github' => \Carbon\Carbon::parse($issueData['created_at']),
                        'updated_at_github' => \Carbon\Carbon::parse($issueData['updated_at']),
                        'closed_at' => isset($issueData['closed_at'])
                            ? \Carbon\Carbon::parse($issueData['closed_at'])
                            : null,
                    ]
                );
            }

            $repo->update(['issues_synced_at' => now()]);
        } catch (\Exception $e) {
            Log::warning('Failed to sync issues for repo', [
                'repo' => $repo->full_name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncRepoPullRequests(GitHubRepo $repo, GitHubApiService $apiService): void
    {
        Log::info('Syncing PRs for repo', ['repo' => $repo->full_name]);

        try {
            $prs = $apiService->listPullRequests($repo, ['state' => 'all']);

            foreach ($prs as $prData) {
                GitHubPullRequest::updateOrCreate(
                    [
                        'repo_id' => $repo->id,
                        'github_id' => $prData['id'],
                    ],
                    [
                        'number' => $prData['number'],
                        'title' => $prData['title'],
                        'body' => $prData['body'],
                        'state' => $prData['state'],
                        'url' => $prData['html_url'],
                        'author' => $prData['user']['login'] ?? null,
                        'author_avatar' => $prData['user']['avatar_url'] ?? null,
                        'head_branch' => $prData['head']['ref'] ?? null,
                        'base_branch' => $prData['base']['ref'] ?? null,
                        'head_sha' => $prData['head']['sha'] ?? null,
                        'is_draft' => $prData['draft'] ?? false,
                        'mergeable' => $prData['mergeable'] ?? null,
                        'mergeable_state' => $prData['mergeable_state'] ?? null,
                        'additions' => $prData['additions'] ?? 0,
                        'deletions' => $prData['deletions'] ?? 0,
                        'changed_files' => $prData['changed_files'] ?? 0,
                        'comments_count' => $prData['comments'] ?? 0,
                        'review_comments_count' => $prData['review_comments'] ?? 0,
                        'labels' => collect($prData['labels'] ?? [])->pluck('name')->toArray(),
                        'assignees' => collect($prData['assignees'] ?? [])->pluck('login')->toArray(),
                        'reviewers' => collect($prData['requested_reviewers'] ?? [])->pluck('login')->toArray(),
                        'created_at_github' => \Carbon\Carbon::parse($prData['created_at']),
                        'updated_at_github' => \Carbon\Carbon::parse($prData['updated_at']),
                        'merged_at' => isset($prData['merged_at'])
                            ? \Carbon\Carbon::parse($prData['merged_at'])
                            : null,
                        'closed_at' => isset($prData['closed_at'])
                            ? \Carbon\Carbon::parse($prData['closed_at'])
                            : null,
                    ]
                );
            }

            $repo->update(['prs_synced_at' => now()]);
        } catch (\Exception $e) {
            Log::warning('Failed to sync PRs for repo', [
                'repo' => $repo->full_name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
