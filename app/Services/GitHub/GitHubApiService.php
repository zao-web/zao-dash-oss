<?php

namespace App\Services\GitHub;

use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Task;
use Illuminate\Support\Facades\Http;

class GitHubApiService
{
    private const API_URL = 'https://api.github.com';

    public function __construct(
        private GitHubAppService $app
    ) {}

    /**
     * Get authenticated HTTP client for a repo
     */
    private function client(GitHubRepo $repo): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->app->getInstallationToken($repo->installation);

        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json']);
    }

    /**
     * List issues for a repo
     */
    public function listIssues(GitHubRepo $repo, array $params = []): array
    {
        $defaults = ['state' => 'open', 'per_page' => 100];

        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/issues", array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list issues: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a single issue
     */
    public function getIssue(GitHubRepo $repo, int $issueNumber): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/issues/{$issueNumber}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get issue: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create an issue
     */
    public function createIssue(GitHubRepo $repo, string $title, string $body, array $labels = []): array
    {
        $response = $this->client($repo)
            ->post(self::API_URL."/repos/{$repo->full_name}/issues", [
                'title' => $title,
                'body' => $body,
                'labels' => $labels,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to create issue: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Store/update an issue in our database
     */
    public function storeIssue(GitHubRepo $repo, array $issue): GitHubIssue
    {
        $labels = array_map(fn ($l) => $l['name'], $issue['labels'] ?? []);
        $assignees = array_map(fn ($a) => $a['login'], $issue['assignees'] ?? []);

        return GitHubIssue::updateOrCreate(
            [
                'repo_id' => $repo->id,
                'issue_number' => $issue['number'],
            ],
            [
                'issue_id' => $issue['id'],
                'title' => $issue['title'],
                'body' => $issue['body'] ?? null,
                'state' => $issue['state'],
                'labels' => $labels,
                'assignees' => $assignees,
                'closed_at' => isset($issue['closed_at']) ? now()->parse($issue['closed_at']) : null,
            ]
        );
    }

    /**
     * Sync issues to tasks bidirectionally
     */
    public function syncIssueToTask(GitHubIssue $issue): ?Task
    {
        if ($issue->task_id) {
            // Update existing task
            $task = $issue->task;
            if ($task) {
                $task->update([
                    'title' => $issue->title,
                    'description' => $issue->body,
                    'status' => $issue->isOpen() ? 'pending' : 'completed',
                ]);
            }

            return $task;
        }

        // Create new task
        $repo = $issue->repo;
        $task = Task::create([
            'title' => $issue->title,
            'description' => $issue->body,
            'project_id' => $repo->project_id,
            'source' => "github:{$repo->full_name}#{$issue->issue_number}",
            'status' => 'pending',
            'priority' => $issue->hasLabel('urgent') || $issue->hasLabel('high-priority') ? 'high' : 'medium',
        ]);

        $issue->update(['task_id' => $task->id]);

        return $task;
    }

    /**
     * List pull requests for a repo
     */
    public function listPullRequests(GitHubRepo $repo, array $params = []): array
    {
        $defaults = ['state' => 'open', 'per_page' => 100];

        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/pulls", array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list PRs: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a single pull request
     */
    public function getPullRequest(GitHubRepo $repo, int $prNumber): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/pulls/{$prNumber}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get PR: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Store/update a pull request in our database
     */
    public function storePullRequest(GitHubRepo $repo, array $pr): GitHubPullRequest
    {
        $reviewers = array_map(fn ($r) => $r['login'], $pr['requested_reviewers'] ?? []);

        return GitHubPullRequest::updateOrCreate(
            [
                'repo_id' => $repo->id,
                'pr_number' => $pr['number'],
            ],
            [
                'pr_id' => $pr['id'],
                'title' => $pr['title'],
                'body' => $pr['body'] ?? null,
                'state' => $pr['merged_at'] ? 'merged' : $pr['state'],
                'base_branch' => $pr['base']['ref'],
                'head_branch' => $pr['head']['ref'],
                'author' => $pr['user']['login'],
                'reviewers' => $reviewers,
                'merged_at' => isset($pr['merged_at']) ? now()->parse($pr['merged_at']) : null,
            ]
        );
    }

    /**
     * Merge a pull request
     */
    public function mergePullRequest(GitHubRepo $repo, int $prNumber, string $mergeMethod = 'squash'): array
    {
        $response = $this->client($repo)
            ->put(self::API_URL."/repos/{$repo->full_name}/pulls/{$prNumber}/merge", [
                'merge_method' => $mergeMethod,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to merge PR: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Add a comment to a PR
     */
    public function addPrComment(GitHubRepo $repo, int $prNumber, string $body): array
    {
        $response = $this->client($repo)
            ->post(self::API_URL."/repos/{$repo->full_name}/issues/{$prNumber}/comments", [
                'body' => $body,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to add comment: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create or update a repository secret
     */
    public function setRepoSecret(GitHubRepo $repo, string $secretName, string $secretValue): void
    {
        // First get the public key for the repo
        $keyResponse = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/actions/secrets/public-key");

        if (! $keyResponse->successful()) {
            throw new \Exception('Failed to get public key: '.$keyResponse->body());
        }

        $keyData = $keyResponse->json();
        $publicKey = base64_decode($keyData['key']);
        $keyId = $keyData['key_id'];

        // Encrypt the secret using libsodium
        $sealed = sodium_crypto_box_seal($secretValue, $publicKey);
        $encryptedValue = base64_encode($sealed);

        // Set the secret
        $response = $this->client($repo)
            ->put(self::API_URL."/repos/{$repo->full_name}/actions/secrets/{$secretName}", [
                'encrypted_value' => $encryptedValue,
                'key_id' => $keyId,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to set secret: '.$response->body());
        }
    }

    /**
     * List commits for a repo
     */
    public function listCommits(GitHubRepo $repo, ?string $since = null, ?string $until = null, int $perPage = 100): array
    {
        $params = ['per_page' => $perPage];

        if ($since) {
            $params['since'] = $since;
        }
        if ($until) {
            $params['until'] = $until;
        }

        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/commits", $params);

        if (! $response->successful()) {
            throw new \Exception('Failed to list commits: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a single commit with full details (files changed, stats)
     */
    public function getCommit(GitHubRepo $repo, string $sha): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/commits/{$sha}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get commit: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create or update a workflow file
     */
    public function createWorkflowFile(GitHubRepo $repo, string $content, string $filename = 'deploy.yml'): array
    {
        $path = ".github/workflows/{$filename}";

        // Check if file exists
        $existsResponse = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/contents/{$path}");

        $sha = null;
        if ($existsResponse->successful()) {
            $sha = $existsResponse->json()['sha'];
        }

        $payload = [
            'message' => 'Add deployment workflow (via Zao Dash)',
            'content' => base64_encode($content),
            'branch' => $repo->default_branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = $this->client($repo)
            ->put(self::API_URL."/repos/{$repo->full_name}/contents/{$path}", $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create workflow: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Dispatch a GitHub Actions workflow for a branch or ref.
     */
    public function dispatchWorkflow(GitHubRepo $repo, string $workflowIdentifier, string $ref, array $inputs = []): void
    {
        $payload = ['ref' => $ref];

        if ($inputs !== []) {
            $payload['inputs'] = $inputs;
        }

        $response = $this->client($repo)
            ->post(self::API_URL."/repos/{$repo->full_name}/actions/workflows/{$workflowIdentifier}/dispatches", $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to dispatch workflow: '.$response->body());
        }
    }

    /**
     * Rerun a previously completed GitHub Actions workflow run.
     */
    public function rerunWorkflowRun(GitHubRepo $repo, int $workflowRunId): void
    {
        $response = $this->client($repo)
            ->post(self::API_URL."/repos/{$repo->full_name}/actions/runs/{$workflowRunId}/rerun");

        if (! $response->successful()) {
            throw new \Exception('Failed to rerun workflow run: '.$response->body());
        }
    }
}
