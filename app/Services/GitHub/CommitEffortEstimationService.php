<?php

namespace App\Services\GitHub;

use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CommitEffortEstimationService
{
    /**
     * Author usernames that produce AI-authored commits. These get a flat
     * tiny review-time hours estimate instead of being measured against the
     * full diff — the human's effort was reviewing + merging, not writing.
     */
    protected array $aiAuthorPatterns = [
        '/^claude(\[bot\])?$/i',
        '/^claude-code(-bot)?$/i',
        '/^claude-sonnet/i',
        '/^claude-opus/i',
        '/^github-actions(\[bot\])?$/i',
        '/^dependabot(\[bot\])?$/i',
        '/^renovate(\[bot\])?$/i',
        '/^cursor(\[bot\])?$/i',
    ];

    /** Flat hours per AI-authored commit (review time only). */
    protected float $aiCommitReviewHours = 0.1;

    /**
     * Per-commit base hours. Tuned conservatively: a feature shipped in 5
     * commits shouldn't read as 12.5 hours of effort when the real work
     * was maybe 4. Multi-commit features compound naturally; over-counting
     * here gets amplified when paired with Slack/email signals describing
     * the same work.
     */
    protected array $commitTypeBaseHours = [
        'feature' => 1.0,
        'bugfix' => 0.5,
        'refactor' => 0.75,
        'docs' => 0.2,
        'test' => 0.3,
        'chore' => 0.15,
        'style' => 0.1,
        'merge' => 0.0,
        'revert' => 0.15,
        'default' => 0.5,
    ];

    protected array $filesChangedMultiplier = [
        1 => 1.0,
        3 => 1.25,
        5 => 1.5,
        10 => 2.0,
        20 => 2.5,
        50 => 3.0,
    ];

    protected array $linesChangedMultiplier = [
        10 => 1.0,
        50 => 1.25,
        100 => 1.5,
        250 => 2.0,
        500 => 2.5,
        1000 => 3.0,
    ];

    public function __construct(
        private GitHubApiService $github,
        private ?GitHubUserApiService $userApi = null
    ) {}

    public function estimateProjectEffort(Project $project, ?User $user = null): array
    {
        $repos = $project->githubRepos;

        if ($repos->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No GitHub repositories linked to this project',
                'repos' => [],
            ];
        }

        $lastTimeEntry = TimeEntry::where('project_id', $project->id)
            ->orderBy('spent_date', 'desc')
            ->first();

        $sinceDate = $lastTimeEntry?->spent_date?->toIso8601String();

        $results = [
            'success' => true,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'since_date' => $sinceDate ? Carbon::parse($sinceDate)->format('M d, Y') : 'All time',
            'last_time_entry' => $lastTimeEntry ? [
                'date' => $lastTimeEntry->spent_date->format('M d, Y'),
                'hours' => $lastTimeEntry->hours,
                'notes' => $lastTimeEntry->notes,
            ] : null,
            'repos' => [],
            'total_commits' => 0,
            'total_estimated_hours' => 0,
            'breakdown' => [
                'feature' => 0,
                'bugfix' => 0,
                'refactor' => 0,
                'docs' => 0,
                'test' => 0,
                'chore' => 0,
                'other' => 0,
            ],
        ];

        foreach ($repos as $repo) {
            try {
                $repoResult = $this->estimateRepoEffort($repo, $sinceDate, $user);
                $results['repos'][] = $repoResult;
                $results['total_commits'] += $repoResult['commit_count'];
                $results['total_estimated_hours'] += $repoResult['estimated_hours'];

                foreach ($repoResult['breakdown'] as $type => $hours) {
                    $key = in_array($type, ['feature', 'bugfix', 'refactor', 'docs', 'test', 'chore'])
                        ? $type
                        : 'other';
                    $results['breakdown'][$key] += $hours;
                }
            } catch (\Exception $e) {
                Log::error('Failed to estimate effort for repo', [
                    'repo' => $repo->full_name,
                    'error' => $e->getMessage(),
                ]);
                $results['repos'][] = [
                    'repo' => $repo->full_name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $results['total_estimated_hours'] = round($results['total_estimated_hours'] * 4) / 4;

        return $results;
    }

    public function estimateRepoEffort(GitHubRepo $repo, ?string $since = null, ?User $user = null): array
    {
        $commits = $this->fetchCommits($repo, $since, $user);

        $result = [
            'repo' => $repo->full_name,
            'repo_id' => $repo->id,
            'commit_count' => count($commits),
            'estimated_hours' => 0,
            'breakdown' => [],
            'commits' => [],
        ];

        $breakdown = [];

        foreach ($commits as $commitSummary) {
            if ($this->isMergeCommit($commitSummary)) {
                continue;
            }

            try {
                $commit = $this->fetchCommit($repo, $commitSummary['sha'], $user);
            } catch (\Exception) {
                $commit = $commitSummary;
            }

            $authorName = $commitSummary['commit']['author']['name'] ?? '';
            $authorLogin = $commitSummary['author']['login'] ?? '';
            $isAi = $this->isAiAuthor($authorName) || $this->isAiAuthor($authorLogin);

            if ($isAi) {
                // AI commits: human's effort was review + merge, not authoring.
                $estimation = [
                    'type' => 'ai',
                    'hours' => $this->aiCommitReviewHours,
                    'files_changed' => count($commit['files'] ?? []),
                    'lines_changed' => ($commit['stats']['additions'] ?? 0) + ($commit['stats']['deletions'] ?? 0),
                ];
            } else {
                $estimation = $this->estimateCommitHours($commit);
            }

            $result['estimated_hours'] += $estimation['hours'];
            $result['commits'][] = [
                'sha' => substr($commitSummary['sha'], 0, 7),
                'message' => $this->truncateMessage($commitSummary['commit']['message']),
                'author' => $authorName ?: $authorLogin ?: 'Unknown',
                'date' => $commitSummary['commit']['author']['date'] ?? null,
                'type' => $estimation['type'],
                'hours' => $estimation['hours'],
                'is_ai_authored' => $isAi,
                'files_changed' => $estimation['files_changed'],
                'lines_changed' => $estimation['lines_changed'],
            ];

            $type = $isAi ? 'ai' : $estimation['type'];
            $breakdown[$type] = ($breakdown[$type] ?? 0) + $estimation['hours'];
        }

        $result['breakdown'] = $breakdown;
        $result['estimated_hours'] = round($result['estimated_hours'] * 4) / 4;

        return $result;
    }

    protected function fetchCommits(GitHubRepo $repo, ?string $since, ?User $user): array
    {
        [$owner, $repoName] = explode('/', $repo->full_name);

        // Try user OAuth first if available
        if ($user && $this->userApi && $user->githubCredential) {
            try {
                $params = ['per_page' => 100];
                if ($since) {
                    $params['since'] = $since;
                }

                return $this->userApi->listCommits($user, $owner, $repoName, $params);
            } catch (\Exception $e) {
                Log::debug('User OAuth failed for commits, falling back to App token', [
                    'repo' => $repo->full_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to GitHub App token
        return $this->github->listCommits($repo, $since);
    }

    protected function fetchCommit(GitHubRepo $repo, string $sha, ?User $user): array
    {
        [$owner, $repoName] = explode('/', $repo->full_name);

        // Try user OAuth first if available
        if ($user && $this->userApi && $user->githubCredential) {
            try {
                return $this->userApi->getCommit($user, $owner, $repoName, $sha);
            } catch (\Exception $e) {
                Log::debug('User OAuth failed for commit, falling back to App token', [
                    'repo' => $repo->full_name,
                    'sha' => $sha,
                ]);
            }
        }

        // Fall back to GitHub App token
        return $this->github->getCommit($repo, $sha);
    }

    protected function estimateCommitHours(array $commit): array
    {
        $message = $commit['commit']['message'] ?? '';
        $type = $this->detectCommitType($message);
        $baseHours = $this->commitTypeBaseHours[$type] ?? $this->commitTypeBaseHours['default'];

        $filesChanged = count($commit['files'] ?? []);
        $additions = 0;
        $deletions = 0;

        if (isset($commit['stats'])) {
            $additions = $commit['stats']['additions'] ?? 0;
            $deletions = $commit['stats']['deletions'] ?? 0;
        } elseif (isset($commit['files'])) {
            foreach ($commit['files'] as $file) {
                $additions += $file['additions'] ?? 0;
                $deletions += $file['deletions'] ?? 0;
            }
        }

        $linesChanged = $additions + $deletions;

        $filesMultiplier = $this->getMultiplier($filesChanged, $this->filesChangedMultiplier);
        $linesMultiplier = $this->getMultiplier($linesChanged, $this->linesChangedMultiplier);

        $complexityMultiplier = min(4.0, ($filesMultiplier * 0.4) + ($linesMultiplier * 0.6));

        $hours = max(0.25, min(8.0, $baseHours * $complexityMultiplier));

        return [
            'type' => $type,
            'hours' => round($hours * 4) / 4,
            'files_changed' => $filesChanged,
            'lines_changed' => $linesChanged,
            'base_hours' => $baseHours,
            'complexity_multiplier' => round($complexityMultiplier, 2),
        ];
    }

    protected function detectCommitType(string $message): string
    {
        $message = strtolower(trim($message));
        $firstLine = strtok($message, "\n");

        if (preg_match('/^(feat|feature|add|implement)[\(:]/', $firstLine)) {
            return 'feature';
        }
        if (preg_match('/^(fix|bug|hotfix|patch)[\(:]/', $firstLine)) {
            return 'bugfix';
        }
        if (preg_match('/^(refactor|clean|improve|optimize)[\(:]/', $firstLine)) {
            return 'refactor';
        }
        if (preg_match('/^(docs?|readme|comment)[\(:]/', $firstLine)) {
            return 'docs';
        }
        if (preg_match('/^(test|spec|coverage)[\(:]/', $firstLine)) {
            return 'test';
        }
        if (preg_match('/^(chore|build|ci|deps?)[\(:]/', $firstLine)) {
            return 'chore';
        }
        if (preg_match('/^(style|format|lint)[\(:]/', $firstLine)) {
            return 'style';
        }
        if (preg_match('/^revert[\(:]/', $firstLine)) {
            return 'revert';
        }

        if (preg_match('/\b(add|create|implement|new feature|feat)\b/', $firstLine)) {
            return 'feature';
        }
        if (preg_match('/\b(fix|bug|issue|error|broken|crash)\b/', $firstLine)) {
            return 'bugfix';
        }
        if (preg_match('/\b(refactor|clean|improve|optimize|restructure)\b/', $firstLine)) {
            return 'refactor';
        }
        if (preg_match('/\b(doc|readme|comment|guide)\b/', $firstLine)) {
            return 'docs';
        }
        if (preg_match('/\b(test|spec|coverage)\b/', $firstLine)) {
            return 'test';
        }
        if (preg_match('/\b(chore|build|ci|deps?|dependency|package|version)\b/', $firstLine)) {
            return 'chore';
        }

        return 'default';
    }

    public function isAiAuthor(?string $author): bool
    {
        if (! $author) {
            return false;
        }
        foreach ($this->aiAuthorPatterns as $pattern) {
            if (preg_match($pattern, $author)) {
                return true;
            }
        }

        return false;
    }

    protected function isMergeCommit(array $commit): bool
    {
        if (isset($commit['parents']) && count($commit['parents']) > 1) {
            return true;
        }

        $message = strtolower($commit['commit']['message'] ?? '');

        return str_starts_with($message, 'merge ')
            || str_contains($message, 'merge pull request')
            || str_contains($message, 'merge branch');
    }

    protected function getMultiplier(int $value, array $thresholds): float
    {
        $multiplier = 1.0;
        foreach ($thresholds as $threshold => $mult) {
            if ($value >= $threshold) {
                $multiplier = $mult;
            }
        }

        return $multiplier;
    }

    protected function truncateMessage(string $message, int $maxLength = 80): string
    {
        $firstLine = strtok(trim($message), "\n");
        if (strlen($firstLine) > $maxLength) {
            return substr($firstLine, 0, $maxLength - 3).'...';
        }

        return $firstLine;
    }
}
