<?php

namespace App\Services\GitHub;

use App\Models\GitHubRepo;
use App\Models\VaultSecretGitHubSyncEvent;
use App\Models\VaultSecretGitHubTarget;
use App\Services\Vault\VaultService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubSecretSyncService
{
    private const API_URL = 'https://api.github.com';

    public function __construct(
        private GitHubAppService $app,
        private VaultService $vault
    ) {}

    private function client(GitHubRepo $repo): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->app->getInstallationToken($repo->installation);

        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json']);
    }

    public function listGitHubSecrets(GitHubRepo $repo): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/actions/secrets");

        if (! $response->successful()) {
            throw new \Exception('Failed to list GitHub secrets: '.$response->body());
        }

        return $response->json()['secrets'] ?? [];
    }

    public function listGitHubEnvironmentSecrets(GitHubRepo $repo, string $environment): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/environments/{$environment}/secrets");

        if (! $response->successful()) {
            throw new \Exception('Failed to list GitHub environment secrets: '.$response->body());
        }

        return $response->json()['secrets'] ?? [];
    }

    public function checkRequirements(
        GitHubRepo $repo,
        string $environment,
        array $requiredSecrets
    ): array {
        $githubSecrets = collect($this->listGitHubSecrets($repo))
            ->pluck('name')
            ->toArray();

        $projectId = $repo->project_id;
        $clientId = $repo->client_id;

        $inVault = [];
        $missingInVault = [];

        foreach ($requiredSecrets as $secretKey) {
            if ($this->vault->existsForEnvironment($secretKey, $environment, $projectId, $clientId)) {
                $inVault[] = $secretKey;
            } else {
                $missingInVault[] = $secretKey;
            }
        }

        $inGitHub = array_intersect($requiredSecrets, $githubSecrets);
        $missingInGitHub = array_diff($inVault, $githubSecrets);

        return [
            'in_vault' => $inVault,
            'in_github' => array_values($inGitHub),
            'missing_in_vault' => $missingInVault,
            'missing_in_github' => array_values($missingInGitHub),
        ];
    }

    public function syncToGitHub(
        GitHubRepo $repo,
        string $environment,
        array $secretKeys,
        bool $force = false,
        bool $useGitHubEnvironments = false
    ): array {
        $pushed = [];
        $failed = [];
        $skippedDrift = [];
        $skippedNotFound = [];

        $projectId = $repo->project_id;
        $clientId = $repo->client_id;

        foreach ($secretKeys as $secretKey) {
            $target = VaultSecretGitHubTarget::where('github_repo_id', $repo->id)
                ->where('environment', $environment)
                ->whereHas('vaultSecret', fn ($q) => $q->where('key', $secretKey))
                ->first();

            if ($target && $target->drift_status === 'modified_on_github' && ! $force) {
                $skippedDrift[] = $secretKey;

                continue;
            }

            $valueInfo = $this->vault->getValueWithFingerprint($secretKey, $environment, $projectId, $clientId);

            if (! $valueInfo) {
                $skippedNotFound[] = $secretKey;

                continue;
            }

            try {
                if ($useGitHubEnvironments) {
                    $this->pushEnvironmentSecret($repo, $environment, $secretKey, $valueInfo['value']);
                } else {
                    $this->pushRepoSecret($repo, $secretKey, $valueInfo['value']);
                }

                $this->recordTarget($repo, $valueInfo, $secretKey, $environment, $useGitHubEnvironments ? $environment : null);
                $this->logSyncEvent($repo, $valueInfo['secret_id'], 'push', 'success');

                $pushed[] = $secretKey;
            } catch (\Exception $e) {
                $this->logSyncEvent(
                    $repo,
                    $valueInfo['secret_id'],
                    'push',
                    'failure',
                    $e->getMessage()
                );
                $failed[] = $secretKey;

                Log::warning('Failed to push secret to GitHub', [
                    'repo' => $repo->full_name,
                    'secret' => $secretKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'pushed' => $pushed,
            'failed' => $failed,
            'skipped_drift' => $skippedDrift,
            'skipped_not_found' => $skippedNotFound,
        ];
    }

    private function pushRepoSecret(GitHubRepo $repo, string $secretName, string $secretValue): void
    {
        $keyData = $this->getRepoPublicKey($repo);
        $encryptedValue = $this->encryptSecret($secretValue, $keyData['key']);

        $response = $this->client($repo)
            ->put(self::API_URL."/repos/{$repo->full_name}/actions/secrets/{$secretName}", [
                'encrypted_value' => $encryptedValue,
                'key_id' => $keyData['key_id'],
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to set secret: '.$response->body());
        }
    }

    private function pushEnvironmentSecret(
        GitHubRepo $repo,
        string $environment,
        string $secretName,
        string $secretValue
    ): void {
        $keyData = $this->getEnvironmentPublicKey($repo, $environment);
        $encryptedValue = $this->encryptSecret($secretValue, $keyData['key']);

        $response = $this->client($repo)
            ->put(
                self::API_URL."/repos/{$repo->full_name}/environments/{$environment}/secrets/{$secretName}",
                [
                    'encrypted_value' => $encryptedValue,
                    'key_id' => $keyData['key_id'],
                ]
            );

        if (! $response->successful()) {
            throw new \Exception('Failed to set environment secret: '.$response->body());
        }
    }

    private function getRepoPublicKey(GitHubRepo $repo): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/actions/secrets/public-key");

        if (! $response->successful()) {
            throw new \Exception('Failed to get public key: '.$response->body());
        }

        return $response->json();
    }

    private function getEnvironmentPublicKey(GitHubRepo $repo, string $environment): array
    {
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/environments/{$environment}/secrets/public-key");

        if (! $response->successful()) {
            throw new \Exception('Failed to get environment public key: '.$response->body());
        }

        return $response->json();
    }

    private function encryptSecret(string $value, string $publicKeyBase64): string
    {
        $publicKey = base64_decode($publicKeyBase64);
        $sealed = sodium_crypto_box_seal($value, $publicKey);

        return base64_encode($sealed);
    }

    private function recordTarget(
        GitHubRepo $repo,
        array $valueInfo,
        string $secretKey,
        string $environment,
        ?string $githubEnvironment = null
    ): void {
        VaultSecretGitHubTarget::updateOrCreate(
            [
                'github_repo_id' => $repo->id,
                'vault_secret_id' => $valueInfo['secret_id'],
                'environment' => $environment,
            ],
            [
                'github_secret_name' => $secretKey,
                'github_environment_name' => $githubEnvironment,
                'is_managed' => true,
                'last_pushed_at' => now(),
                'last_pushed_fingerprint' => $valueInfo['fingerprint'],
                'drift_status' => 'in_sync',
            ]
        );
    }

    private function logSyncEvent(
        GitHubRepo $repo,
        int $secretId,
        string $action,
        string $status,
        ?string $errorMessage = null
    ): void {
        VaultSecretGitHubSyncEvent::create([
            'github_repo_id' => $repo->id,
            'vault_secret_id' => $secretId,
            'action' => $action,
            'status' => $status,
            'error_message' => $errorMessage,
            'triggered_by_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    public function detectDrift(GitHubRepo $repo): array
    {
        $githubSecrets = collect($this->listGitHubSecrets($repo))
            ->keyBy('name');

        $targets = VaultSecretGitHubTarget::where('github_repo_id', $repo->id)
            ->where('is_managed', true)
            ->get();

        $drifted = [];
        $inSync = [];
        $missingOnGitHub = [];

        foreach ($targets as $target) {
            $githubSecret = $githubSecrets->get($target->github_secret_name);

            if (! $githubSecret) {
                $target->update(['drift_status' => 'missing_on_github']);
                $missingOnGitHub[] = $target->github_secret_name;

                continue;
            }

            $githubUpdatedAt = \Carbon\Carbon::parse($githubSecret['updated_at']);
            $target->update(['github_updated_at' => $githubUpdatedAt]);

            if ($target->last_pushed_at && $githubUpdatedAt->isAfter($target->last_pushed_at)) {
                $target->update(['drift_status' => 'modified_on_github']);
                $drifted[] = $target->github_secret_name;
            } else {
                $target->update(['drift_status' => 'in_sync']);
                $inSync[] = $target->github_secret_name;
            }
        }

        return [
            'drifted' => $drifted,
            'in_sync' => $inSync,
            'missing_on_github' => $missingOnGitHub,
        ];
    }

    public function getSyncStatus(GitHubRepo $repo): array
    {
        $targets = VaultSecretGitHubTarget::where('github_repo_id', $repo->id)->get();

        return [
            'total' => $targets->count(),
            'in_sync' => $targets->where('drift_status', 'in_sync')->count(),
            'drifted' => $targets->where('drift_status', 'modified_on_github')->count(),
            'missing' => $targets->where('drift_status', 'missing_on_github')->count(),
            'targets' => $targets->map(fn ($t) => [
                'secret_key' => $t->vaultSecret?->key,
                'github_name' => $t->github_secret_name,
                'environment' => $t->environment,
                'drift_status' => $t->drift_status,
                'last_pushed_at' => $t->last_pushed_at?->toIso8601String(),
            ])->toArray(),
        ];
    }
}
