<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LaravelCloudService
{
    protected string $baseUrl = 'https://cloud.laravel.com/api';

    public function __construct(
        protected ?string $apiToken = null,
        protected ?string $applicationId = null
    ) {
        $this->apiToken = $apiToken ?? config('services.laravel_cloud.api_token');
        $this->applicationId = $applicationId ?? config('services.laravel_cloud.application_id');
    }

    /**
     * Create a preview environment for a branch.
     *
     * @return array{environment_id: string, vanity_domain: string|null, status: string}
     */
    public function createPreviewEnvironment(string $branch, string $name): array
    {
        $response = $this->client()->post(
            "/applications/{$this->applicationId}/environments",
            [
                'name' => Str::limit($name, 40, ''),
                'branch' => $branch,
            ]
        );

        if (! $response->successful()) {
            Log::error('Laravel Cloud: Failed to create preview environment', [
                'branch' => $branch,
                'name' => $name,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new \RuntimeException(
                'Failed to create preview environment: '
                .($response->json('errors.0.detail') ?? $response->body())
            );
        }

        $data = $response->json('data');

        return [
            'environment_id' => $data['id'],
            'vanity_domain' => $data['attributes']['vanity_domain'] ?? null,
            'status' => $data['attributes']['status'] ?? 'unknown',
        ];
    }

    /**
     * Deploy an environment (triggers a build + deploy).
     *
     * @return array{deployment_id: string, status: string}
     */
    public function deploy(string $environmentId): array
    {
        $response = $this->client()->post("/environments/{$environmentId}/deployments");

        if (! $response->successful()) {
            Log::error('Laravel Cloud: Failed to trigger deployment', [
                'environment_id' => $environmentId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new \RuntimeException(
                'Failed to trigger deployment: '
                .($response->json('errors.0.detail') ?? $response->body())
            );
        }

        $data = $response->json('data');

        return [
            'deployment_id' => $data['id'],
            'status' => $data['attributes']['status'] ?? 'pending',
        ];
    }

    /**
     * Get the current status and URL of an environment.
     *
     * @return array{status: string, vanity_domain: string|null, name: string}
     */
    public function getEnvironment(string $environmentId): array
    {
        $response = $this->client()->get("/environments/{$environmentId}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to get environment: '.$response->body());
        }

        $data = $response->json('data');

        return [
            'status' => $data['attributes']['status'] ?? 'unknown',
            'vanity_domain' => $data['attributes']['vanity_domain'] ?? null,
            'name' => $data['attributes']['name'] ?? '',
        ];
    }

    /**
     * @param  array<int, string>  $include
     * @return array{data: array<int, array<string, mixed>>, included: array<int, array<string, mixed>>}
     */
    public function listEnvironments(array $include = []): array
    {
        $response = $this->client()->get(
            "/applications/{$this->applicationId}/environments",
            $include === [] ? [] : ['include' => implode(',', $include)],
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to list environments: '.$response->body());
        }

        return [
            'data' => $response->json('data', []),
            'included' => $response->json('included', []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listInstances(string $environmentId): array
    {
        $response = $this->client()->get("/environments/{$environmentId}/instances");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to list instances: '.$response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBackgroundProcesses(string $instanceId): array
    {
        $response = $this->client()->get("/instances/{$instanceId}/background-processes");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to list background processes: '.$response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Get deployment status.
     *
     * @return array{status: string, branch_name: string|null, commit_hash: string|null, failure_reason: string|null}
     */
    public function getDeployment(string $deploymentId): array
    {
        $response = $this->client()->get("/deployments/{$deploymentId}");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to get deployment: '.$response->body());
        }

        $data = $response->json('data');

        return [
            'status' => $data['attributes']['status'] ?? 'unknown',
            'branch_name' => $data['attributes']['branch_name'] ?? null,
            'commit_hash' => $data['attributes']['commit_hash'] ?? null,
            'failure_reason' => $data['attributes']['failure_reason'] ?? null,
        ];
    }

    /**
     * Delete a preview environment.
     */
    public function deleteEnvironment(string $environmentId): bool
    {
        $response = $this->client()->delete("/environments/{$environmentId}");

        if (! $response->successful()) {
            Log::error('Laravel Cloud: Failed to delete environment', [
                'environment_id' => $environmentId,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if the service is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiToken) && ! empty($this->applicationId);
    }

    protected function client(): PendingRequest
    {
        if (! $this->apiToken) {
            throw new \RuntimeException('Laravel Cloud API token not configured. Set LARAVEL_CLOUD_API_TOKEN in your environment.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->timeout(30);
    }
}
