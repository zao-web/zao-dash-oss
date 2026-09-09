<?php

use App\Models\Client;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\VaultSecret;
use App\Models\VaultSecretGitHubTarget;
use App\Models\VaultSecretValue;
use App\Services\GitHub\GitHubSecretSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function mockPublicKeyResponse(): array
{
    return [
        'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'key_id' => 'test-key-id-'.uniqid(),
    ];
}

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
    ]);
    $this->repo = GitHubRepo::factory()->create([
        'full_name' => 'zao-web/sierra-theme',
        'project_id' => $this->project->id,
        'client_id' => $this->client->id,
    ]);

    $this->syncService = app(GitHubSecretSyncService::class);
});

test('list github secrets returns secret names and metadata', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets' => Http::response([
            'total_count' => 2,
            'secrets' => [
                [
                    'name' => 'FTP_HOST',
                    'created_at' => '2024-01-01T00:00:00Z',
                    'updated_at' => '2024-06-01T00:00:00Z',
                ],
                [
                    'name' => 'FTP_PASSWORD',
                    'created_at' => '2024-01-01T00:00:00Z',
                    'updated_at' => '2024-06-15T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    $secrets = $this->syncService->listGitHubSecrets($this->repo);

    expect($secrets)->toHaveCount(2)
        ->and($secrets[0]['name'])->toBe('FTP_HOST')
        ->and($secrets[1]['name'])->toBe('FTP_PASSWORD');
});

test('list github environment secrets', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/environments/staging/secrets' => Http::response([
            'total_count' => 1,
            'secrets' => [
                [
                    'name' => 'STAGING_API_KEY',
                    'created_at' => '2024-01-01T00:00:00Z',
                    'updated_at' => '2024-06-01T00:00:00Z',
                ],
            ],
        ], 200),
    ]);

    $secrets = $this->syncService->listGitHubEnvironmentSecrets($this->repo, 'staging');

    expect($secrets)->toHaveCount(1)
        ->and($secrets[0]['name'])->toBe('STAGING_API_KEY');
});

test('check requirements returns missing and available secrets', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets' => Http::response([
            'secrets' => [
                ['name' => 'FTP_HOST', 'updated_at' => '2024-06-01T00:00:00Z'],
            ],
        ], 200),
    ]);

    $secret1 = VaultSecret::factory()->create([
        'key' => 'FTP_HOST',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret1->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('host-value'),
        'is_active' => true,
    ]);

    $secret2 = VaultSecret::factory()->create([
        'key' => 'FTP_PASSWORD',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret2->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('password-value'),
        'is_active' => true,
    ]);

    $result = $this->syncService->checkRequirements(
        repo: $this->repo,
        environment: 'staging',
        requiredSecrets: ['FTP_HOST', 'FTP_PASSWORD', 'FTP_USER']
    );

    expect($result['in_vault'])->toContain('FTP_HOST', 'FTP_PASSWORD')
        ->and($result['in_github'])->toContain('FTP_HOST')
        ->and($result['missing_in_vault'])->toContain('FTP_USER')
        ->and($result['missing_in_github'])->toContain('FTP_PASSWORD');
});

test('sync pushes missing secrets to github', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets' => Http::response([
            'secrets' => [],
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response([
            'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'key_id' => 'test-key-id',
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/*' => Http::response([], 201),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'NEW_SECRET',
        'project_id' => $this->project->id,
    ]);

    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('secret-value'),
        'is_active' => true,
    ]);

    $result = $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['NEW_SECRET']
    );

    expect($result['pushed'])->toContain('NEW_SECRET')
        ->and($result['failed'])->toBeEmpty();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'actions/secrets/NEW_SECRET');
    });
});

test('sync creates github target record', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'TARGET_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('value'),
        'value_fingerprint' => 'abc123',
        'is_active' => true,
    ]);

    $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['TARGET_SECRET']
    );

    $this->assertDatabaseHas('vault_secret_github_targets', [
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'TARGET_SECRET',
        'drift_status' => 'in_sync',
    ]);
});

test('sync updates existing github target record', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'UPDATE_TARGET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('new-value'),
        'value_fingerprint' => 'new-fingerprint',
        'is_active' => true,
    ]);

    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'last_pushed_fingerprint' => 'old-fingerprint',
        'last_pushed_at' => now()->subDay(),
    ]);

    $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['UPDATE_TARGET']
    );

    $target->refresh();
    expect($target->last_pushed_fingerprint)->not->toBe('old-fingerprint')
        ->and($target->last_pushed_at->isToday())->toBeTrue();
});

test('sync logs sync event', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'LOGGED_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('value'),
        'is_active' => true,
    ]);

    $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['LOGGED_SECRET']
    );

    $this->assertDatabaseHas('vault_secret_github_sync_events', [
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'action' => 'push',
        'status' => 'success',
    ]);
});

test('sync logs failure event on error', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response([
            'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'key_id' => 'test-key-id',
        ], 200),
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/FAIL_SECRET' => Http::response([
            'message' => 'Not found',
        ], 404),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'FAIL_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('value'),
        'is_active' => true,
    ]);

    $result = $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['FAIL_SECRET']
    );

    expect($result['failed'])->toContain('FAIL_SECRET');

    $this->assertDatabaseHas('vault_secret_github_sync_events', [
        'github_repo_id' => $this->repo->id,
        'action' => 'push',
        'status' => 'failure',
    ]);
});

test('detect drift compares github updated_at with last push', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets' => Http::response([
            'secrets' => [
                [
                    'name' => 'DRIFT_SECRET',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
        ], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'DRIFT_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'github_secret_name' => 'DRIFT_SECRET',
        'last_pushed_at' => now()->subHour(),
        'drift_status' => 'in_sync',
    ]);

    $this->syncService->detectDrift($this->repo);

    $this->assertDatabaseHas('vault_secret_github_targets', [
        'github_repo_id' => $this->repo->id,
        'github_secret_name' => 'DRIFT_SECRET',
        'drift_status' => 'modified_on_github',
    ]);
});

test('detect drift marks in_sync when no changes', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets' => Http::response([
            'secrets' => [
                [
                    'name' => 'STABLE_SECRET',
                    'updated_at' => now()->subDay()->toIso8601String(),
                ],
            ],
        ], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'STABLE_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'github_secret_name' => 'STABLE_SECRET',
        'last_pushed_at' => now()->subHour(),
        'drift_status' => 'unknown',
    ]);

    $this->syncService->detectDrift($this->repo);

    $this->assertDatabaseHas('vault_secret_github_targets', [
        'github_repo_id' => $this->repo->id,
        'github_secret_name' => 'STABLE_SECRET',
        'drift_status' => 'in_sync',
    ]);
});

test('force sync overwrites even with drift', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'FORCE_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('forced-value'),
        'is_active' => true,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'drift_status' => 'modified_on_github',
    ]);

    $result = $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['FORCE_SECRET'],
        force: true
    );

    expect($result['pushed'])->toContain('FORCE_SECRET');
});

test('sync skips secrets with drift without force flag', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/actions/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'SKIP_DRIFT',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('value'),
        'is_active' => true,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'drift_status' => 'modified_on_github',
    ]);

    $result = $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['SKIP_DRIFT'],
        force: false
    );

    expect($result['skipped_drift'])->toContain('SKIP_DRIFT')
        ->and($result['pushed'])->not->toContain('SKIP_DRIFT');
});

test('sync to github environment secret', function () {
    Http::fake([
        'api.github.com/repos/zao-web/sierra-theme/environments/staging/secrets/public-key' => Http::response(mockPublicKeyResponse(), 200),
        'api.github.com/repos/zao-web/sierra-theme/environments/staging/secrets/*' => Http::response([], 201),
        '*' => Http::response([], 200),
    ]);

    $secret = VaultSecret::factory()->create([
        'key' => 'ENV_SECRET',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('env-value'),
        'is_active' => true,
    ]);

    $result = $this->syncService->syncToGitHub(
        repo: $this->repo,
        environment: 'staging',
        secretKeys: ['ENV_SECRET'],
        useGitHubEnvironments: true
    );

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'environments/staging/secrets/ENV_SECRET');
    });
});

test('get sync status summary for repo', function () {
    $secret1 = VaultSecret::factory()->create(['key' => 'SECRET_1']);
    $secret2 = VaultSecret::factory()->create(['key' => 'SECRET_2']);
    $secret3 = VaultSecret::factory()->create(['key' => 'SECRET_3']);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret1->id,
        'drift_status' => 'in_sync',
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret2->id,
        'drift_status' => 'modified_on_github',
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $secret3->id,
        'drift_status' => 'missing_on_github',
    ]);

    $summary = $this->syncService->getSyncStatus($this->repo);

    expect($summary['total'])->toBe(3)
        ->and($summary['in_sync'])->toBe(1)
        ->and($summary['drifted'])->toBe(1)
        ->and($summary['missing'])->toBe(1);
});
