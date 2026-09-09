<?php

namespace App\Services\GitHub;

use App\Models\GitHubCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GitHubOAuthService
{
    private const AUTH_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const USER_URL = 'https://api.github.com/user';

    private array $scopes = [
        'read:user',
        'user:email',
        'repo',
    ];

    public function getAuthUrl(?string $state = null): string
    {
        $state = $state ?? Str::random(40);

        $params = [
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => $this->getRedirectUri(),
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::accept('application/json')
            ->post(self::TOKEN_URL, [
                'client_id' => config('services.github.client_id'),
                'client_secret' => config('services.github.client_secret'),
                'code' => $code,
                'redirect_uri' => $this->getRedirectUri(),
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to exchange code for tokens: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['error'])) {
            throw new \Exception('GitHub OAuth error: '.($data['error_description'] ?? $data['error']));
        }

        return $data;
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::USER_URL);

        if (! $response->successful()) {
            throw new \Exception('Failed to get user info: '.$response->body());
        }

        return $response->json();
    }

    public function getUserEmails(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::USER_URL.'/emails');

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }

    public function storeCredentials(User $user, array $tokens): GitHubCredential
    {
        $userInfo = $this->getUserInfo($tokens['access_token']);
        $emails = $this->getUserEmails($tokens['access_token']);

        $primaryEmail = collect($emails)->firstWhere('primary', true)['email']
            ?? $userInfo['email']
            ?? null;

        return GitHubCredential::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds($tokens['expires_in'])
                    : null,
                'scopes' => isset($tokens['scope']) ? explode(',', $tokens['scope']) : $this->scopes,
                'github_id' => (string) $userInfo['id'],
                'username' => $userInfo['login'],
                'email' => $primaryEmail,
                'avatar_url' => $userInfo['avatar_url'] ?? null,
            ]
        );
    }

    public function getValidAccessToken(User $user): ?string
    {
        $credential = $user->githubCredential;

        if (! $credential) {
            return null;
        }

        if ($credential->isExpired() && $credential->refresh_token) {
            $credential = $this->refreshAccessToken($credential);
        }

        return $credential->access_token;
    }

    public function refreshAccessToken(GitHubCredential $credential): GitHubCredential
    {
        if (! $credential->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        $response = Http::accept('application/json')
            ->post(self::TOKEN_URL, [
                'client_id' => config('services.github.client_id'),
                'client_secret' => config('services.github.client_secret'),
                'refresh_token' => $credential->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh token: '.$response->body());
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'expires_at' => isset($data['expires_in'])
                ? now()->addSeconds($data['expires_in'])
                : null,
        ]);

        return $credential->fresh();
    }

    public function revokeAccess(GitHubCredential $credential): bool
    {
        $credential->delete();

        return true;
    }

    public function hasValidCredentials(User $user): bool
    {
        $credential = $user->githubCredential;

        return $credential !== null && ! $credential->isExpired();
    }

    private function getRedirectUri(): string
    {
        return config('services.github.redirect_uri', url('/auth/github/callback'));
    }
}
