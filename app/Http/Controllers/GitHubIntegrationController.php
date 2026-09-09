<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGitHubJob;
use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Services\GitHub\CommitEffortEstimationService;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GitHubIntegrationController extends Controller
{
    public function __construct(
        private GitHubAppService $app,
        private GitHubApiService $api,
        private CommitEffortEstimationService $effortEstimator
    ) {}

    public function status()
    {
        $installations = GitHubInstallation::with(['repos' => function ($q) {
            $q->where('monitoring_enabled', true);
        }])->get();

        return response()->json([
            'connected' => $installations->isNotEmpty(),
            'installations' => $installations->map(fn ($i) => [
                'id' => $i->id,
                'installation_id' => $i->installation_id,
                'account_type' => $i->account_type,
                'account_login' => $i->account_login,
                'repos_count' => $i->repos->count(),
            ]),
        ]);
    }

    public function installUrl()
    {
        return response()->json([
            'url' => $this->app->getInstallationUrl(),
        ]);
    }

    public function callback(Request $request)
    {
        $installationId = $request->input('installation_id');
        $setupAction = $request->input('setup_action');

        if ($setupAction === 'install' && $installationId) {
            try {
                // Fetch installation details and sync
                $installations = $this->app->listInstallations();
                $installation = collect($installations)->firstWhere('id', (int) $installationId);

                if ($installation) {
                    $record = $this->app->storeInstallation($installation);
                    $this->app->syncRepos($record);

                    // Auto-link repos to projects based on name matching
                    $this->performAutoLinking();

                    // Dispatch full sync job for issues and PRs
                    SyncGitHubJob::dispatch($record->id)->onQueue('sync');
                }

                return redirect('/settings/integrations')
                    ->with('success', 'GitHub App installed! Syncing your repos, issues, and PRs now...');
            } catch (\Exception $e) {
                return redirect('/settings/integrations')
                    ->with('error', 'Failed to complete GitHub installation: '.$e->getMessage());
            }
        }

        return redirect('/settings/integrations')
            ->with('error', 'GitHub installation was cancelled or failed.');
    }

    public function syncInstallations()
    {
        try {
            $installations = $this->app->listInstallations();
            $synced = 0;

            foreach ($installations as $installation) {
                $record = $this->app->storeInstallation($installation);
                $this->app->syncRepos($record);
                $synced++;
            }

            // Auto-link repos to projects based on name matching
            $autoLinked = $this->performAutoLinking();

            return response()->json([
                'success' => true,
                'synced' => $synced,
                'auto_linked' => $autoLinked['linked'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function listRepos(GitHubInstallation $installation)
    {
        $repos = $installation->repos()
            ->withCount(['issues' => fn ($q) => $q->where('state', 'open')])
            ->withCount(['pullRequests' => fn ($q) => $q->where('state', 'open')])
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'repos' => $repos->map(fn ($r) => [
                'id' => $r->id,
                'repo_id' => $r->repo_id,
                'full_name' => $r->full_name,
                'is_private' => $r->is_private,
                'default_branch' => $r->default_branch,
                'client_id' => $r->client_id,
                'project_id' => $r->project_id,
                'monitoring_enabled' => $r->monitoring_enabled,
                'deployment_type' => $r->deployment_type,
                'open_issues_count' => $r->issues_count,
                'open_prs_count' => $r->pull_requests_count,
                'url' => $r->url,
            ]),
        ]);
    }

    public function updateRepo(Request $request, GitHubRepo $repo)
    {
        $validated = $request->validate([
            'client_id' => 'sometimes|nullable|exists:clients,id',
            'project_id' => 'sometimes|nullable|exists:projects,id',
            'monitoring_enabled' => 'sometimes|boolean',
            'deployment_type' => 'sometimes|nullable|string|in:sftp,vercel,netlify,wordpress,none',
        ]);

        $repo->update($validated);

        return response()->json(['success' => true, 'repo' => $repo]);
    }

    public function syncIssues(GitHubRepo $repo)
    {
        try {
            $issues = $this->api->listIssues($repo);
            $synced = 0;

            foreach ($issues as $issue) {
                // Skip pull requests (GitHub returns PRs in issues endpoint)
                if (isset($issue['pull_request'])) {
                    continue;
                }

                $record = $this->api->storeIssue($repo, $issue);

                // Sync to task if monitoring enabled
                if ($repo->monitoring_enabled) {
                    $this->api->syncIssueToTask($record);
                }

                $synced++;
            }

            return response()->json([
                'success' => true,
                'synced' => $synced,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function syncPullRequests(GitHubRepo $repo)
    {
        try {
            $prs = $this->api->listPullRequests($repo);
            $synced = 0;

            foreach ($prs as $pr) {
                $this->api->storePullRequest($repo, $pr);
                $synced++;
            }

            return response()->json([
                'success' => true,
                'synced' => $synced,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function listOpenPrs()
    {
        $prs = GitHubPullRequest::with('repo')
            ->where('state', 'open')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'pull_requests' => $prs->map(fn ($pr) => [
                'id' => $pr->id,
                'pr_number' => $pr->pr_number,
                'title' => $pr->title,
                'author' => $pr->author,
                'base_branch' => $pr->base_branch,
                'head_branch' => $pr->head_branch,
                'approval_status' => $pr->approval_status,
                'needs_approval' => $pr->needsApproval(),
                'checks_passed' => $pr->checks_passed,
                'repo_name' => $pr->repo->full_name,
                'url' => $pr->url,
                'created_at' => $pr->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function approvePr(Request $request, GitHubPullRequest $pr)
    {
        $pr->update(['approval_status' => 'approved']);

        // If targeting develop, auto-merge
        if ($pr->targetsDevelop() && $pr->checks_passed) {
            try {
                $this->api->mergePullRequest($pr->repo, $pr->pr_number);
                $pr->update(['state' => 'merged', 'merged_at' => now()]);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Approved but failed to merge: '.$e->getMessage()], 500);
            }
        }

        return response()->json(['success' => true, 'pr' => $pr]);
    }

    public function rejectPr(Request $request, GitHubPullRequest $pr)
    {
        $pr->update(['approval_status' => 'rejected']);

        return response()->json(['success' => true, 'pr' => $pr]);
    }

    /**
     * Get repos linked to a project.
     */
    public function projectRepos(Project $project)
    {
        $repos = $project->githubRepos()
            ->withCount(['issues' => fn ($q) => $q->where('state', 'open')])
            ->withCount(['pullRequests' => fn ($q) => $q->where('state', 'open')])
            ->get();

        return response()->json([
            'repos' => $repos->map(fn ($r) => [
                'id' => $r->id,
                'full_name' => $r->full_name,
                'is_private' => $r->is_private,
                'default_branch' => $r->default_branch,
                'open_issues_count' => $r->issues_count,
                'open_prs_count' => $r->pull_requests_count,
                'url' => $r->url,
            ]),
        ]);
    }

    public function availableRepos(Request $request, Project $project)
    {
        $user = $request->user();

        // Get all unlinked repos (or repos linked to this project) from GitHub App
        $appRepos = GitHubRepo::whereNull('project_id')
            ->orWhere('project_id', $project->id)
            ->withCount(['issues' => fn ($q) => $q->where('state', 'open')])
            ->withCount(['pullRequests' => fn ($q) => $q->where('state', 'open')])
            ->orderBy('full_name')
            ->get();

        // Also fetch user's personal repos if they have OAuth connected
        $userRepos = collect();
        $hasUserOAuth = false;
        if ($user->githubCredential) {
            $hasUserOAuth = true;
            try {
                $userApiService = app(\App\Services\GitHub\GitHubUserApiService::class);

                // Fetch multiple pages to get more repos
                $allUserRepos = [];
                for ($page = 1; $page <= 3; $page++) {
                    $pageData = $userApiService->listUserRepos($user, [
                        'per_page' => 100,
                        'sort' => 'full_name',
                        'page' => $page,
                    ]);

                    if (empty($pageData)) {
                        break;
                    }

                    $allUserRepos = array_merge($allUserRepos, $pageData);

                    if (count($pageData) < 100) {
                        break;
                    }
                }

                // Filter out repos we already have from App installations
                $appRepoFullNames = $appRepos->pluck('full_name')->toArray();

                $userRepos = collect($allUserRepos)
                    ->filter(fn ($r) => ! in_array($r['full_name'], $appRepoFullNames))
                    ->map(fn ($r) => [
                        'id' => null,
                        'full_name' => $r['full_name'],
                        'name' => $r['name'],
                        'is_private' => $r['private'],
                        'default_branch' => $r['default_branch'] ?? 'main',
                        'is_linked' => false,
                        'open_issues_count' => $r['open_issues_count'] ?? 0,
                        'open_prs_count' => 0,
                        'url' => $r['html_url'],
                        'source' => 'user_oauth',
                    ]);

                // Check specifically for Locumpedia
                $locumRepos = collect($allUserRepos)->filter(fn ($r) => str_contains(strtolower($r['full_name']), 'locum'));

                Log::info('GitHub User OAuth repos fetched', [
                    'user_id' => $user->id,
                    'total_fetched' => count($allUserRepos),
                    'after_filter' => $userRepos->count(),
                    'locum_repos_found' => $locumRepos->pluck('full_name')->toArray(),
                    'permissions_sample' => collect($allUserRepos)->take(3)->map(fn ($r) => [
                        'name' => $r['full_name'],
                        'permissions' => $r['permissions'] ?? 'none',
                    ])->toArray(),
                ]);
            } catch (\Exception $e) {
                Log::error('GitHub User OAuth repos failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Calculate match scores based on project/client name similarity
        $projectSlug = Str::slug($project->name);
        $clientSlug = $project->client ? Str::slug($project->client->name) : '';

        $appReposWithScores = $appRepos->map(function ($r) use ($projectSlug, $clientSlug, $project) {
            return $this->buildRepoWithScore($r, $projectSlug, $clientSlug, $project, 'github_app');
        });

        $userReposWithScores = $userRepos->map(function ($r) use ($projectSlug, $clientSlug) {
            $repoSlug = Str::slug($r['name']);
            $score = $this->calculateMatchScore($repoSlug, $projectSlug, $clientSlug);

            return array_merge($r, ['match_score' => $score]);
        });

        // Merge and sort by match score (suggested first), then by name
        $allRepos = $appReposWithScores->concat($userReposWithScores);
        $sorted = $allRepos->sortByDesc('match_score')->values();

        return response()->json([
            'repos' => $sorted,
            'suggestions' => $sorted->where('match_score', '>=', 60)->values(),
            'has_user_oauth' => $hasUserOAuth,
        ]);
    }

    protected function buildRepoWithScore($repo, string $projectSlug, string $clientSlug, Project $project, string $source): array
    {
        $repoSlug = Str::slug($repo->name);
        $score = $this->calculateMatchScore($repoSlug, $projectSlug, $clientSlug);

        return [
            'id' => $repo->id,
            'full_name' => $repo->full_name,
            'name' => $repo->name,
            'is_private' => $repo->is_private,
            'default_branch' => $repo->default_branch,
            'is_linked' => $repo->project_id === $project->id,
            'open_issues_count' => $repo->issues_count ?? 0,
            'open_prs_count' => $repo->pull_requests_count ?? 0,
            'url' => $repo->url,
            'match_score' => $score,
            'source' => $source,
        ];
    }

    protected function calculateMatchScore(string $repoSlug, string $projectSlug, string $clientSlug): int
    {
        // Exact match with project slug
        if ($repoSlug === $projectSlug) {
            return 100;
        }
        // Repo contains project slug
        if (str_contains($repoSlug, $projectSlug) || str_contains($projectSlug, $repoSlug)) {
            return 80;
        }
        // Client name match
        if ($clientSlug && (str_contains($repoSlug, $clientSlug) || str_contains($clientSlug, $repoSlug))) {
            return 60;
        }
        // Partial word matches
        $projectWords = explode('-', $projectSlug);
        $repoWords = explode('-', $repoSlug);
        $matches = count(array_intersect($projectWords, $repoWords));
        if ($matches > 0) {
            return min(50, $matches * 20);
        }

        return 0;
    }

    /**
     * Link a repo to a project.
     */
    public function linkRepo(Request $request, Project $project, GitHubRepo $repo)
    {
        $repo->update(['project_id' => $project->id]);

        return response()->json(['success' => true, 'id' => $repo->id]);
    }

    /**
     * Link an OAuth repo by full_name (creates GitHubRepo record if needed).
     */
    public function linkRepoByName(Request $request, Project $project)
    {
        $validated = $request->validate([
            'full_name' => 'required|string',
        ]);

        $fullName = $validated['full_name'];

        $repo = GitHubRepo::where('full_name', $fullName)->first();

        if (! $repo) {
            [$owner, $name] = explode('/', $fullName, 2);

            $repo = GitHubRepo::create([
                'full_name' => $fullName,
                'owner' => $owner,
                'name' => $name,
                'is_private' => true,
                'default_branch' => 'main',
                'project_id' => $project->id,
            ]);
        } else {
            $repo->update(['project_id' => $project->id]);
        }

        return response()->json([
            'success' => true,
            'id' => $repo->id,
            'repo' => [
                'id' => $repo->id,
                'full_name' => $repo->full_name,
                'name' => $repo->name,
                'is_private' => $repo->is_private,
                'default_branch' => $repo->default_branch,
                'is_linked' => true,
                'url' => $repo->url,
            ],
        ]);
    }

    /**
     * Unlink a repo from a project.
     */
    public function unlinkRepo(Request $request, Project $project, GitHubRepo $repo)
    {
        if ($repo->project_id === $project->id) {
            $repo->update(['project_id' => null]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Auto-link repos to projects based on name matching.
     * Called during GitHub sync or manually.
     */
    public function autoLinkRepos()
    {
        $result = $this->performAutoLinking();

        return response()->json($result);
    }

    public function estimateProjectEffort(Request $request, Project $project)
    {
        $result = $this->effortEstimator->estimateProjectEffort($project, $request->user());

        return response()->json($result);
    }

    /**
     * Perform auto-linking logic (used by API and internal calls).
     */
    protected function performAutoLinking(): array
    {
        $linked = 0;
        $suggestions = [];

        // Get unlinked repos
        $unlinkedRepos = GitHubRepo::whereNull('project_id')->get();

        foreach ($unlinkedRepos as $repo) {
            $repoSlug = Str::slug($repo->name);

            // Try exact match with project slug first
            $project = Project::where('slug', $repoSlug)->first();

            if (! $project) {
                // Try partial match - repo name contains project slug or vice versa
                $project = Project::where('status', '!=', 'archived')
                    ->get()
                    ->first(function ($p) use ($repoSlug) {
                        return str_contains($repoSlug, $p->slug) || str_contains($p->slug, $repoSlug);
                    });
            }

            if ($project) {
                $repo->update(['project_id' => $project->id]);
                $linked++;
            } else {
                // Find potential matches for suggestions
                $potentialMatches = Project::where('status', '!=', 'archived')
                    ->get()
                    ->filter(function ($p) use ($repoSlug) {
                        $projectWords = explode('-', $p->slug);
                        $repoWords = explode('-', $repoSlug);

                        return count(array_intersect($projectWords, $repoWords)) >= 2;
                    });

                if ($potentialMatches->isNotEmpty()) {
                    $suggestions[] = [
                        'repo' => $repo->full_name,
                        'potential_projects' => $potentialMatches->pluck('name')->toArray(),
                    ];
                }
            }
        }

        return [
            'linked' => $linked,
            'suggestions' => $suggestions,
        ];
    }
}
