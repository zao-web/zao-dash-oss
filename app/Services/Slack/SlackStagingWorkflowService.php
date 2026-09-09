<?php

namespace App\Services\Slack;

use App\Models\DeploymentConfig;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\VaultSecret;
use App\Models\VaultSecretGitHubTarget;
use App\Models\VaultSecretValue;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubSecretSyncService;
use App\Services\GitHub\WorkflowSecretDetectorService;
use App\Services\Vault\VaultService;

class SlackStagingWorkflowService
{
    public function __construct(
        private WorkflowSecretDetectorService $workflowSecretDetector,
        private GitHubSecretSyncService $githubSecretSync,
        private GitHubApiService $github,
        private VaultService $vault,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function describeChannelStaging(string $teamId, string $channelId): array
    {
        $context = $this->resolveContext($teamId, $channelId);

        if (isset($context['error'])) {
            return $context;
        }

        return $this->buildStatusPayload(
            $context['workspace'],
            $context['channel'],
            $context['project'],
            $context['repo'],
            $context['deployment'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function storeSecretFromSlack(
        string $teamId,
        string $channelId,
        string $secretName,
        string $secretValue,
        string $targetScope = 'environment',
    ): array {
        $context = $this->resolveContext($teamId, $channelId);

        if (isset($context['error'])) {
            return $context;
        }

        $normalizedSecretName = $this->normalizeSecretName($secretName);

        if ($normalizedSecretName === '') {
            return ['error' => 'Secret name is required.'];
        }

        $environment = $targetScope === 'repository' ? null : VaultSecretValue::ENVIRONMENT_STAGING;
        $project = $context['project'];
        $repo = $context['repo'];

        $secret = VaultSecret::query()
            ->where('key', $normalizedSecretName)
            ->where('project_id', $project->id)
            ->first();

        if ($secret) {
            $this->vault->rotateForEnvironment($secret, $environment, $secretValue);
        } else {
            $secret = $this->vault->storeWithEnvironment(
                $normalizedSecretName,
                $secretValue,
                $normalizedSecretName,
                $environment,
                [
                    'category' => VaultSecret::CATEGORY_CREDENTIAL,
                    'description' => "Slack-provisioned staging secret for {$repo->full_name}",
                    'project_id' => $project->id,
                    'client_id' => $project->client_id,
                    'is_sensitive' => true,
                ]
            );
        }

        $repo->workflowSecretRequirements()
            ->where('secret_name', $normalizedSecretName)
            ->get()
            ->each(fn ($requirement) => $requirement->linkToVaultSecret($secret));

        $syncResult = $this->githubSecretSync->syncToGitHub(
            repo: $repo,
            environment: VaultSecretValue::ENVIRONMENT_STAGING,
            secretKeys: [$normalizedSecretName],
            force: true,
            useGitHubEnvironments: $targetScope !== 'repository',
        );

        $status = $this->describeChannelStaging($teamId, $channelId);

        if (! isset($status['error']) && ($status['ready_to_publish'] ?? false) && $context['deployment']) {
            $context['deployment']->update(['onboarding_completed' => true]);
            $status = $this->describeChannelStaging($teamId, $channelId);
        }

        $status['message'] = ($syncResult['failed'] ?? []) === []
            ? "Saved `{$normalizedSecretName}` and synced it to GitHub."
            : "Saved `{$normalizedSecretName}`, but GitHub sync failed.";
        $status['stored_secret'] = $normalizedSecretName;
        $status['sync_result'] = $syncResult;

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncRequiredSecrets(string $teamId, string $channelId): array
    {
        $context = $this->resolveContext($teamId, $channelId);

        if (isset($context['error'])) {
            return $context;
        }

        $repo = $context['repo'];
        $project = $context['project'];
        $requiredSecrets = $this->getRequiredSecretNames($repo, $context['deployment']);

        $aggregate = [
            'pushed' => [],
            'failed' => [],
            'skipped_drift' => [],
            'skipped_not_found' => [],
        ];

        foreach ($requiredSecrets as $secretName) {
            if (! $this->projectScopedSecretValueExists($secretName, $project, VaultSecretValue::ENVIRONMENT_STAGING)) {
                $aggregate['skipped_not_found'][] = $secretName;

                continue;
            }

            $target = $repo->secretTargets()
                ->where('environment', VaultSecretValue::ENVIRONMENT_STAGING)
                ->whereHas('vaultSecret', fn ($query) => $query->where('key', $secretName))
                ->first();

            $useEnvironmentSecrets = $target
                ? $target->isEnvironmentSecret()
                : true;

            $result = $this->githubSecretSync->syncToGitHub(
                repo: $repo,
                environment: VaultSecretValue::ENVIRONMENT_STAGING,
                secretKeys: [$secretName],
                force: true,
                useGitHubEnvironments: $useEnvironmentSecrets,
            );

            foreach (array_keys($aggregate) as $key) {
                $aggregate[$key] = array_values(array_unique(array_merge($aggregate[$key], $result[$key] ?? [])));
            }
        }

        $status = $this->describeChannelStaging($teamId, $channelId);

        if (! isset($status['error']) && ($status['ready_to_publish'] ?? false) && $context['deployment']) {
            $context['deployment']->update(['onboarding_completed' => true]);
            $status = $this->describeChannelStaging($teamId, $channelId);
        }

        $status['message'] = empty($aggregate['pushed'])
            ? 'No staging secrets were synced.'
            : 'Synced staging secrets to GitHub.';
        $status['sync_result'] = $aggregate;

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishToStaging(string $teamId, string $channelId): array
    {
        $context = $this->resolveContext($teamId, $channelId);

        if (isset($context['error'])) {
            return $context;
        }

        $status = $this->buildStatusPayload(
            $context['workspace'],
            $context['channel'],
            $context['project'],
            $context['repo'],
            $context['deployment'],
        );

        if (! ($status['deployment']['workflow_file_path'] ?? null)) {
            return ['error' => 'This repository does not have a staging workflow configured yet.'];
        }

        if (($status['summary']['missing'] ?? 0) > 0) {
            return ['error' => 'Required staging secrets are still missing from the vault.'];
        }

        if (($status['summary']['pending_sync'] ?? 0) > 0) {
            return ['error' => 'Required staging secrets are saved, but not yet synced to GitHub.'];
        }

        $workflowIdentifier = (string) ($status['deployment']['workflow_identifier'] ?? '');
        $branch = (string) ($status['deployment']['publish_branch'] ?? $context['repo']->default_branch);

        $this->github->dispatchWorkflow($context['repo'], $workflowIdentifier, $branch, []);

        if ($context['deployment']) {
            $context['deployment']->update(['onboarding_completed' => true]);
        }

        $status = $this->describeChannelStaging($teamId, $channelId);
        $status['message'] = "Queued staging publish via `{$workflowIdentifier}` on `{$branch}`.";
        $status['publish'] = [
            'workflow_identifier' => $workflowIdentifier,
            'branch' => $branch,
        ];

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(string $teamId, string $channelId): array
    {
        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $teamId)
            ->first();

        if (! $workspace) {
            return ['error' => 'Workspace not found. Please reconnect Slack integration.'];
        }

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->with(['client', 'project'])
            ->first();

        if (! $channel) {
            return ['error' => 'Slack channel is not linked yet. Use `/zao link client <id> project <id>` first.'];
        }

        $project = $channel->project
            ?? Project::query()
                ->where('slack_channel_id', $channel->id)
                ->first();

        if (! $project) {
            return ['error' => 'This Slack channel is not linked to an active project yet.'];
        }

        $repo = GitHubRepo::query()
            ->with([
                'deploymentConfig',
                'workflowSecretRequirements.vaultSecret.values',
                'secretTargets.vaultSecret.values',
            ])
            ->where('project_id', $project->id)
            ->latest('id')
            ->first();

        if (! $repo) {
            return ['error' => 'This project does not have a linked GitHub repository yet.'];
        }

        $deployment = $repo->deploymentConfig;

        return [
            'workspace' => $workspace,
            'channel' => $channel,
            'project' => $project,
            'repo' => $repo,
            'deployment' => $deployment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusPayload(
        SlackWorkspace $workspace,
        SlackChannel $channel,
        Project $project,
        GitHubRepo $repo,
        ?DeploymentConfig $deployment,
    ): array {
        $repo = $this->ensureWorkflowRequirementsLoaded($repo, $deployment);
        $requiredSecretNames = $this->getRequiredSecretNames($repo, $deployment);
        $requiredSecrets = [];
        $configured = 0;
        $synced = 0;
        $missing = 0;
        $pendingSync = 0;

        foreach ($requiredSecretNames as $secretName) {
            $secret = $this->resolveProjectScopedSecret($secretName, $project);
            $value = $secret?->getValueForEnvironment(VaultSecretValue::ENVIRONMENT_STAGING);
            $target = $this->resolveSecretTarget($repo, $secretName);

            $hasVaultValue = $value !== null;
            $isSynced = $target?->drift_status === VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC;

            if ($hasVaultValue) {
                $configured++;
            } else {
                $missing++;
            }

            if ($isSynced) {
                $synced++;
            } elseif ($hasVaultValue) {
                $pendingSync++;
            }

            $requiredSecrets[] = [
                'name' => $secretName,
                'has_vault_value' => $hasVaultValue,
                'synced_to_github' => $isSynced,
                'sync_status' => ! $hasVaultValue ? 'missing' : ($isSynced ? 'synced' : 'pending_sync'),
                'target_scope' => $target ? ($target->isEnvironmentSecret() ? 'environment' : 'repository') : 'environment',
                'drift_status' => $target?->drift_status ?? VaultSecretGitHubTarget::DRIFT_STATUS_UNKNOWN,
            ];
        }

        $workflowFilePath = $deployment?->workflow_file_path
            ?? ($repo->deployment_config['workflow_file_path'] ?? null);
        $publishBranch = $repo->deployment_config['staging_branch']
            ?? $repo->deployment_config['branch']
            ?? $repo->default_branch;

        return [
            'workspace' => [
                'id' => $workspace->workspace_id,
                'name' => $workspace->workspace_name ?? $workspace->workspace_id,
            ],
            'channel' => [
                'id' => $channel->channel_id,
                'name' => $channel->channel_name ?? $channel->channel_id,
            ],
            'client' => $project->client ? [
                'id' => $project->client->id,
                'name' => $project->client->name,
            ] : null,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'repo' => [
                'id' => $repo->id,
                'full_name' => $repo->full_name,
                'default_branch' => $repo->default_branch,
            ],
            'deployment' => [
                'deployment_type' => $deployment?->deployment_type ?? 'unknown',
                'workflow_file_path' => $workflowFilePath,
                'workflow_identifier' => $workflowFilePath ? basename($workflowFilePath) : null,
                'staging_url' => $deployment?->staging_url ?? ($repo->deployment_config['staging_url'] ?? null),
                'publish_branch' => $publishBranch,
                'onboarding_completed' => (bool) ($deployment?->onboarding_completed ?? false),
            ],
            'required_secrets' => $requiredSecrets,
            'summary' => [
                'required' => count($requiredSecretNames),
                'configured' => $configured,
                'synced' => $synced,
                'missing' => $missing,
                'pending_sync' => $pendingSync,
            ],
            'ready_to_publish' => $workflowFilePath !== null && $missing === 0 && $pendingSync === 0,
            'success' => true,
        ];
    }

    private function normalizeSecretName(string $secretName): string
    {
        $normalized = strtoupper(trim($secretName));
        $normalized = preg_replace('/[^A-Z0-9]+/', '_', $normalized) ?? '';

        return trim($normalized, '_');
    }

    private function ensureWorkflowRequirementsLoaded(GitHubRepo $repo, ?DeploymentConfig $deployment): GitHubRepo
    {
        $workflowFilePath = $deployment?->workflow_file_path
            ?? ($repo->deployment_config['workflow_file_path'] ?? null);

        if ($repo->workflowSecretRequirements->isEmpty() && $workflowFilePath) {
            $this->workflowSecretDetector->detectAndStore($repo, clearOldParsed: true);

            return GitHubRepo::query()
                ->with([
                    'deploymentConfig',
                    'workflowSecretRequirements.vaultSecret.values',
                    'secretTargets.vaultSecret.values',
                ])
                ->findOrFail($repo->id);
        }

        return $repo;
    }

    /**
     * @return array<int, string>
     */
    private function getRequiredSecretNames(GitHubRepo $repo, ?DeploymentConfig $deployment): array
    {
        $workflowRequirements = $repo->workflowSecretRequirements
            ->where('is_required', true)
            ->pluck('secret_name')
            ->all();

        $deploymentSecrets = is_array($deployment?->secrets)
            ? array_filter(array_map('strval', $deployment->secrets))
            : [];

        $requiredSecrets = array_merge($workflowRequirements, $deploymentSecrets);
        $requiredSecrets = array_map(fn (string $secretName): string => $this->normalizeSecretName($secretName), $requiredSecrets);
        $requiredSecrets = array_filter($requiredSecrets);
        sort($requiredSecrets);

        return array_values(array_unique($requiredSecrets));
    }

    private function resolveProjectScopedSecret(string $secretName, Project $project): ?VaultSecret
    {
        return VaultSecret::query()
            ->where('key', $secretName)
            ->where(function ($query) use ($project) {
                $query->where('project_id', $project->id)
                    ->orWhere(function ($nested) use ($project) {
                        $nested->whereNull('project_id')
                            ->where('client_id', $project->client_id);
                    })
                    ->orWhere(function ($nested) {
                        $nested->whereNull('project_id')
                            ->whereNull('client_id');
                    });
            })
            ->orderByRaw('CASE WHEN project_id = ? THEN 0 WHEN client_id IS NOT NULL THEN 1 ELSE 2 END', [$project->id])
            ->with('values')
            ->first();
    }

    private function resolveSecretTarget(GitHubRepo $repo, string $secretName): ?VaultSecretGitHubTarget
    {
        return $repo->secretTargets
            ->first(function (VaultSecretGitHubTarget $target) use ($secretName): bool {
                return $target->environment === VaultSecretValue::ENVIRONMENT_STAGING
                    && $target->vaultSecret?->key === $secretName;
            });
    }

    private function projectScopedSecretValueExists(string $secretName, Project $project, string $environment): bool
    {
        return $this->vault->getValueWithFingerprint(
            key: $secretName,
            environment: $environment,
            projectId: $project->id,
            clientId: $project->client_id,
        ) !== null;
    }
}
