<?php

use App\Models\Client;
use App\Models\DeploymentConfig;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\VaultSecret;
use App\Models\VaultSecretGitHubTarget;
use App\Models\VaultSecretValue;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use App\Services\Slack\SlackStagingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function stagingWorkflowContext(): array
{
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = Client::factory()->create([
        'name' => 'Acme',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
        'channel_name' => 'acme-platform',
    ]);

    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'slack_channel_id' => $channel->id,
    ]);

    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
        'default_branch' => 'main',
    ]);

    $deployment = DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'staging_url' => 'https://staging.acme.test',
        'onboarding_completed' => false,
    ]);

    return compact('workspace', 'client', 'channel', 'project', 'repo', 'deployment');
}

beforeEach(function () {
    $this->service = app(SlackStagingWorkflowService::class);
});

test('describeChannelStaging returns missing required secrets for the linked repo', function () {
    ['repo' => $repo] = stagingWorkflowContext();

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    $result = $this->service->describeChannelStaging('T12345', 'C12345');

    expect($result['success'])->toBeTrue()
        ->and($result['repo']['full_name'])->toBe('acme/platform')
        ->and($result['summary']['required'])->toBe(1)
        ->and($result['summary']['missing'])->toBe(1)
        ->and($result['ready_to_publish'])->toBeFalse()
        ->and($result['required_secrets'][0]['name'])->toBe('VERCEL_TOKEN')
        ->and($result['required_secrets'][0]['sync_status'])->toBe('missing');
});

test('storeSecretFromSlack stores the secret and syncs it to github', function () {
    ['repo' => $repo, 'deployment' => $deployment, 'project' => $project] = stagingWorkflowContext();

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    $this->mock(GitHubAppService::class)
        ->shouldReceive('getInstallationToken')
        ->andReturn('test-installation-token');

    Http::fake([
        'api.github.com/repos/acme/platform/environments/staging/secrets/public-key' => Http::response([
            'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'key_id' => 'test-key-id',
        ], 200),
        'api.github.com/repos/acme/platform/environments/staging/secrets/*' => Http::response([], 201),
    ]);

    $result = $this->service->storeSecretFromSlack(
        teamId: 'T12345',
        channelId: 'C12345',
        secretName: 'vercel-token',
        secretValue: 'super-secret-value',
        targetScope: 'environment',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['stored_secret'])->toBe('VERCEL_TOKEN')
        ->and($result['summary']['configured'])->toBe(1)
        ->and($result['summary']['synced'])->toBe(1)
        ->and($result['ready_to_publish'])->toBeTrue();

    $secret = VaultSecret::query()
        ->where('project_id', $project->id)
        ->where('key', 'VERCEL_TOKEN')
        ->first();

    expect($secret)->not->toBeNull()
        ->and($secret?->values()->where('environment', 'staging')->exists())->toBeTrue();

    expect(Crypt::decryptString($secret->values()->where('environment', 'staging')->first()->encrypted_value))
        ->toBe('super-secret-value');

    $this->assertDatabaseHas('vault_secret_github_targets', [
        'github_repo_id' => $repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
        'drift_status' => 'in_sync',
    ]);

    expect($deployment->fresh()->onboarding_completed)->toBeTrue();
});

test('publishToStaging dispatches the configured workflow when staging is ready', function () {
    ['repo' => $repo, 'project' => $project] = stagingWorkflowContext();

    $secret = VaultSecret::factory()->create([
        'project_id' => $project->id,
        'client_id' => $project->client_id,
        'key' => 'VERCEL_TOKEN',
        'name' => 'VERCEL_TOKEN',
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('ready-value'),
        'is_active' => true,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'vault_secret_id' => $secret->id,
        'match_status' => \App\Models\GitHubWorkflowSecretRequirement::MATCH_STATUS_MATCHED,
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
        'drift_status' => VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('dispatchWorkflow')
        ->once()
        ->with(
            \Mockery::on(fn (GitHubRepo $repoModel) => $repoModel->id === $repo->id),
            'deploy.yml',
            'main',
            []
        );

    $service = app(SlackStagingWorkflowService::class);
    $result = $service->publishToStaging('T12345', 'C12345');

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain('Queued staging publish')
        ->and($result['publish']['workflow_identifier'])->toBe('deploy.yml')
        ->and($result['publish']['branch'])->toBe('main');
});
