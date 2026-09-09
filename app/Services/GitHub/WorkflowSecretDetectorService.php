<?php

namespace App\Services\GitHub;

use App\Models\GitHubRepo;
use App\Models\GitHubWorkflowSecretRequirement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowSecretDetectorService
{
    private const API_URL = 'https://api.github.com';

    private const BUILT_IN_SECRETS = ['GITHUB_TOKEN'];

    public function __construct(
        private GitHubAppService $app
    ) {}

    private function client(GitHubRepo $repo): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->app->getInstallationToken($repo->installation);

        return Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json']);
    }

    /**
     * @return array|array{required: array, optional: array}
     */
    public function extractSecretsFromYaml(
        string $yaml,
        bool $excludeBuiltIn = false,
        bool $parseWorkflowCall = false
    ): array {
        if (preg_match('/\$\{\{\s*secrets\[/', $yaml)) {
            return [];
        }

        $pattern = '/\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}/';
        preg_match_all($pattern, $yaml, $matches);
        $secrets = array_unique($matches[1] ?? []);

        if ($excludeBuiltIn) {
            $secrets = array_values(array_diff($secrets, self::BUILT_IN_SECRETS));
        }

        if (! $parseWorkflowCall) {
            return array_values($secrets);
        }

        $workflowCallSecrets = $this->parseWorkflowCallSecrets($yaml);

        if (! empty($workflowCallSecrets['required']) || ! empty($workflowCallSecrets['optional'])) {
            return $workflowCallSecrets;
        }

        return array_values($secrets);
    }

    public function extractSecretsWithEnvironments(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException) {
            return [];
        }

        $result = [];
        $jobs = $parsed['jobs'] ?? [];

        foreach ($jobs as $jobName => $job) {
            $environment = $job['environment'] ?? null;

            if (! $environment) {
                continue;
            }

            if (is_array($environment)) {
                $environment = $environment['name'] ?? null;
            }

            if (! $environment) {
                continue;
            }

            $jobYaml = Yaml::dump([$jobName => $job]);
            $pattern = '/\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}/';
            preg_match_all($pattern, $jobYaml, $matches);

            $secrets = array_unique($matches[1] ?? []);

            if (! isset($result[$environment])) {
                $result[$environment] = [];
            }

            $result[$environment] = array_values(array_unique(
                array_merge($result[$environment], $secrets)
            ));
        }

        return $result;
    }

    public function detectAllRequirements(GitHubRepo $repo): array
    {
        $workflows = $this->fetchWorkflowFiles($repo);

        if (empty($workflows)) {
            return [];
        }

        $result = [];

        foreach ($workflows as $workflow) {
            $path = $workflow['path'];
            $content = $this->fetchWorkflowContent($repo, $path);

            if ($content) {
                $secrets = $this->extractSecretsFromYaml($content);
                $result[$path] = $secrets;
            }
        }

        return $result;
    }

    public function detectAndStore(GitHubRepo $repo, bool $clearOldParsed = false): array
    {
        if ($clearOldParsed) {
            GitHubWorkflowSecretRequirement::where('github_repo_id', $repo->id)
                ->where('source', GitHubWorkflowSecretRequirement::SOURCE_PARSED)
                ->delete();
        }

        $requirements = $this->detectAllRequirements($repo);
        $stored = [];

        foreach ($requirements as $workflowPath => $secrets) {
            foreach ($secrets as $secretName) {
                $requirement = GitHubWorkflowSecretRequirement::updateOrCreate(
                    [
                        'github_repo_id' => $repo->id,
                        'workflow_path' => $workflowPath,
                        'secret_name' => $secretName,
                    ],
                    [
                        'source' => GitHubWorkflowSecretRequirement::SOURCE_PARSED,
                        'is_required' => true,
                        'detected_at' => now(),
                    ]
                );

                $stored[] = $requirement;
            }
        }

        return $stored;
    }

    public function getAllRequiredSecrets(GitHubRepo $repo): array
    {
        return GitHubWorkflowSecretRequirement::where('github_repo_id', $repo->id)
            ->where('is_required', true)
            ->distinct()
            ->pluck('secret_name')
            ->toArray();
    }

    private function parseWorkflowCallSecrets(string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException) {
            return ['required' => [], 'optional' => []];
        }

        $required = [];
        $optional = [];

        $workflowCallSecrets = $parsed['on']['workflow_call']['secrets'] ?? [];

        foreach ($workflowCallSecrets as $secretName => $config) {
            if (is_array($config) && isset($config['required']) && $config['required'] === true) {
                $required[] = $secretName;
            } else {
                $optional[] = $secretName;
            }
        }

        return [
            'required' => $required,
            'optional' => $optional,
        ];
    }

    private function fetchWorkflowFiles(GitHubRepo $repo): array
    {
        /** @var Response $response */
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/contents/.github/workflows");

        if (! $response->successful()) {
            if ($response->status() === 404) {
                return [];
            }

            Log::warning('Failed to fetch workflow files', [
                'repo' => $repo->full_name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $files = $response->json();

        return array_filter($files, function ($file) {
            return $file['type'] === 'file' &&
                (str_ends_with($file['name'], '.yml') || str_ends_with($file['name'], '.yaml'));
        });
    }

    private function fetchWorkflowContent(GitHubRepo $repo, string $path): ?string
    {
        /** @var Response $response */
        $response = $this->client($repo)
            ->get(self::API_URL."/repos/{$repo->full_name}/contents/{$path}");

        if (! $response->successful()) {
            Log::warning('Failed to fetch workflow content', [
                'repo' => $repo->full_name,
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        $content = $response->json()['content'] ?? null;

        if (! $content) {
            return null;
        }

        return base64_decode($content);
    }
}
