<?php

namespace App\Services\GitHub;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class GitHubUserApiService
{
    private const API_URL = 'https://api.github.com';

    public function __construct(
        private GitHubOAuthService $oauth
    ) {}

    private function client(User $user): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->oauth->getValidAccessToken($user);

        if (! $token) {
            throw new \Exception('No valid GitHub token for user');
        }

        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json']);
    }

    public function listUserRepos(User $user, array $params = []): array
    {
        $defaults = [
            'per_page' => 100,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ];

        $response = $this->client($user)
            ->get(self::API_URL.'/user/repos', array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list repos: '.$response->body());
        }

        return $response->json();
    }

    public function getRepo(User $user, string $owner, string $repo): array
    {
        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get repo: '.$response->body());
        }

        return $response->json();
    }

    public function listCommits(User $user, string $owner, string $repo, array $params = []): array
    {
        $defaults = ['per_page' => 100];

        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}/commits", array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list commits: '.$response->body());
        }

        return $response->json();
    }

    public function getCommit(User $user, string $owner, string $repo, string $sha): array
    {
        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}/commits/{$sha}");

        if (! $response->successful()) {
            throw new \Exception('Failed to get commit: '.$response->body());
        }

        return $response->json();
    }

    public function listBranches(User $user, string $owner, string $repo): array
    {
        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}/branches", ['per_page' => 100]);

        if (! $response->successful()) {
            throw new \Exception('Failed to list branches: '.$response->body());
        }

        return $response->json();
    }

    public function listIssues(User $user, string $owner, string $repo, array $params = []): array
    {
        $defaults = ['state' => 'open', 'per_page' => 100];

        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}/issues", array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list issues: '.$response->body());
        }

        return $response->json();
    }

    public function listPullRequests(User $user, string $owner, string $repo, array $params = []): array
    {
        $defaults = ['state' => 'open', 'per_page' => 100];

        $response = $this->client($user)
            ->get(self::API_URL."/repos/{$owner}/{$repo}/pulls", array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to list pull requests: '.$response->body());
        }

        return $response->json();
    }

    public function searchRepos(User $user, string $query): array
    {
        $response = $this->client($user)
            ->get(self::API_URL.'/search/repositories', [
                'q' => $query,
                'per_page' => 30,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to search repos: '.$response->body());
        }

        return $response->json();
    }

    public function hasAccess(User $user, string $owner, string $repo): bool
    {
        try {
            $this->getRepo($user, $owner, $repo);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
