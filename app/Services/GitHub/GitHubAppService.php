<?php

namespace App\Services\GitHub;

use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubAppService
{
    private const API_URL = 'https://api.github.com';

    /**
     * Resolve the private key from various sources at runtime.
     * This avoids closure issues with config:cache.
     */
    private function resolvePrivateKey(): ?string
    {
        // Option 1: Explicit file path
        $keyPath = config('services.github.private_key_path');
        if ($keyPath && file_exists($keyPath)) {
            return file_get_contents($keyPath);
        }

        // Option 2: Default storage location
        $storagePath = storage_path('github-key.pem');
        if (file_exists($storagePath)) {
            return file_get_contents($storagePath);
        }

        // Option 3: Base64 encoded env var
        $base64Key = config('services.github.private_key_base64');
        if ($base64Key) {
            return base64_decode($base64Key);
        }

        // Option 4: Direct value (local dev)
        return config('services.github.private_key');
    }

    /**
     * Generate a JWT for GitHub App authentication
     */
    public function generateAppJwt(): string
    {
        $appId = config('services.github.app_id');
        $privateKey = $this->resolvePrivateKey();

        // Debug: log key info (not the key itself)
        Log::debug('GitHub JWT generation', [
            'app_id' => $appId,
            'key_length' => strlen($privateKey ?? ''),
            'key_starts_with' => substr($privateKey ?? '', 0, 30),
            'has_begin_marker' => str_contains($privateKey ?? '', '-----BEGIN'),
        ]);

        if (empty($privateKey)) {
            throw new \Exception('GitHub App private key is not configured');
        }

        if (! str_contains($privateKey, '-----BEGIN')) {
            throw new \Exception('GitHub App private key appears invalid (missing PEM header)');
        }

        // Validate the key can be loaded by OpenSSL
        $keyResource = openssl_pkey_get_private($privateKey);
        if ($keyResource === false) {
            $error = openssl_error_string();
            Log::error('GitHub private key validation failed', [
                'openssl_error' => $error,
                'key_length' => strlen($privateKey),
            ]);
            throw new \Exception('GitHub App private key is invalid: '.($error ?: 'unknown OpenSSL error'));
        }

        $now = time();
        $payload = [
            'iat' => $now - 60, // Issued at time (60 seconds in the past for clock drift)
            'exp' => $now + (10 * 60), // Expires in 10 minutes
            'iss' => $appId,
        ];

        return JWT::encode($payload, $privateKey, 'RS256');
    }

    /**
     * Get an installation access token
     */
    public function getInstallationToken(GitHubInstallation $installation): string
    {
        // Check if we have a valid cached token
        if (! $installation->tokenIsExpired()) {
            return $installation->access_token;
        }

        $jwt = $this->generateAppJwt();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$jwt}",
            'Accept' => 'application/vnd.github+json',
        ])->post(self::API_URL."/app/installations/{$installation->installation_id}/access_tokens");

        if (! $response->successful()) {
            throw new \Exception('Failed to get installation token: '.$response->body());
        }

        $data = $response->json();

        // Update the installation with the new token
        $installation->update([
            'access_token' => $data['token'],
            'token_expires_at' => now()->parse($data['expires_at']),
            'permissions' => $data['permissions'] ?? null,
        ]);

        return $data['token'];
    }

    /**
     * List all installations of the GitHub App
     */
    public function listInstallations(): array
    {
        $jwt = $this->generateAppJwt();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$jwt}",
            'Accept' => 'application/vnd.github+json',
        ])->get(self::API_URL.'/app/installations');

        if (! $response->successful()) {
            throw new \Exception('Failed to list installations: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Store or update an installation
     */
    public function storeInstallation(array $installationData): GitHubInstallation
    {
        $account = $installationData['account'] ?? [];

        return GitHubInstallation::updateOrCreate(
            ['installation_id' => $installationData['id']],
            [
                'account_type' => $account['type'] ?? 'User',
                'account_login' => $account['login'] ?? '',
                'account_id' => $account['id'] ?? 0,
                'repos_access' => $installationData['repository_selection'] ?? 'all',
                'permissions' => $installationData['permissions'] ?? null,
                'connected_at' => now(),
            ]
        );
    }

    /**
     * Sync repositories for an installation
     */
    public function syncRepos(GitHubInstallation $installation): int
    {
        $token = $this->getInstallationToken($installation);
        $count = 0;
        $page = 1;

        Log::info('Starting GitHub repo sync', [
            'installation_id' => $installation->id,
            'account' => $installation->account_login,
        ]);

        do {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get(self::API_URL.'/installation/repositories', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                Log::error('GitHub repo sync failed', [
                    'installation_id' => $installation->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                break;
            }

            $data = $response->json();
            $repos = $data['repositories'] ?? [];

            foreach ($repos as $repo) {
                $pushedAt = isset($repo['pushed_at'])
                    ? \Carbon\Carbon::parse($repo['pushed_at'])
                    : null;

                // Only auto-enable monitoring for repos active in last 6 months
                $isRecentlyActive = $pushedAt && $pushedAt->gte(now()->subMonths(6));
                $isArchived = $repo['archived'] ?? false;

                // Check if repo already exists to preserve manual monitoring settings
                $existingRepo = GitHubRepo::where('repo_id', $repo['id'])->first();

                GitHubRepo::updateOrCreate(
                    ['repo_id' => $repo['id']],
                    [
                        'installation_id' => $installation->id,
                        'owner' => $repo['owner']['login'],
                        'name' => $repo['name'],
                        'full_name' => $repo['full_name'],
                        'is_private' => $repo['private'] ?? false,
                        'is_archived' => $isArchived,
                        'default_branch' => $repo['default_branch'] ?? 'main',
                        'pushed_at' => $pushedAt,
                        // Only set monitoring_enabled for new repos; preserve existing setting
                        'monitoring_enabled' => $existingRepo
                            ? $existingRepo->monitoring_enabled
                            : ($isRecentlyActive && ! $isArchived),
                    ]
                );
                $count++;
            }

            $page++;
        } while (count($repos) === 100);

        Log::info('GitHub repo sync complete', [
            'installation_id' => $installation->id,
            'repos_synced' => $count,
        ]);

        return $count;
    }

    /**
     * Get installation URL for OAuth-like flow
     */
    public function getInstallationUrl(): string
    {
        $appSlug = config('services.github.app_slug');

        return "https://github.com/apps/{$appSlug}/installations/new";
    }

    /**
     * Handle installation webhook (app installed/uninstalled)
     */
    public function handleInstallationWebhook(string $action, array $installation): void
    {
        if ($action === 'created' || $action === 'new_permissions_accepted') {
            $record = $this->storeInstallation($installation);
            $this->syncRepos($record);
        } elseif ($action === 'deleted') {
            GitHubInstallation::where('installation_id', $installation['id'])->delete();
        }
    }
}
