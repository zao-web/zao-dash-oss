<?php

use App\Models\GitHubRepo;
use App\Models\VaultSecret;
use App\Models\VaultSecretGitHubTarget;
use App\Models\VaultSecretValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->repo = GitHubRepo::factory()->create([
        'full_name' => 'zao-web/sierra-theme',
    ]);

    $this->secret = VaultSecret::factory()->create([
        'key' => 'FTP_PASSWORD',
    ]);

    $this->secretValue = VaultSecretValue::factory()->create([
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
    ]);
});

test('github target belongs to repo', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
    ]);

    expect($target->repo)->toBeInstanceOf(GitHubRepo::class)
        ->and($target->repo->id)->toBe($this->repo->id);
});

test('github target belongs to vault secret', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
    ]);

    expect($target->secret)->toBeInstanceOf(VaultSecret::class)
        ->and($target->secret->id)->toBe($this->secret->id);
});

test('github target tracks environment', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
    ]);

    expect($target->environment)->toBe('staging');
});

test('github target tracks github secret name', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'github_secret_name' => 'STAGING_FTP_PASSWORD',
    ]);

    expect($target->github_secret_name)->toBe('STAGING_FTP_PASSWORD');
});

test('github target can have github environment name for environment secrets', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'github_environment_name' => 'staging',
    ]);

    expect($target->github_environment_name)->toBe('staging')
        ->and($target->isEnvironmentSecret())->toBeTrue();
});

test('github target tracks is_managed flag', function () {
    $managed = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'is_managed' => true,
    ]);

    $unmanaged = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => VaultSecret::factory()->create()->id,
        'is_managed' => false,
    ]);

    expect($managed->is_managed)->toBeTrue()
        ->and($unmanaged->is_managed)->toBeFalse();
});

test('github target tracks last push info', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'last_pushed_at' => now(),
        'last_pushed_fingerprint' => 'abc123def456',
    ]);

    expect($target->last_pushed_at)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($target->last_pushed_fingerprint)->toBe('abc123def456');
});

test('github target tracks github updated at for drift detection', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'last_seen_github_updated_at' => now(),
    ]);

    expect($target->last_seen_github_updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('github target has drift status enum', function () {
    $inSync = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'drift_status' => 'in_sync',
    ]);

    $missing = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => VaultSecret::factory()->create()->id,
        'drift_status' => 'missing_on_github',
    ]);

    $modified = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => VaultSecret::factory()->create()->id,
        'drift_status' => 'modified_on_github',
    ]);

    expect($inSync->drift_status)->toBe('in_sync')
        ->and($missing->drift_status)->toBe('missing_on_github')
        ->and($modified->drift_status)->toBe('modified_on_github');
});

test('github target detects drift when status is modified on github', function () {
    $target = VaultSecretGitHubTarget::factory()->modifiedOnGitHub()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'last_pushed_at' => now()->subHour(),
        'last_seen_github_updated_at' => now(),
    ]);

    expect($target->hasDrift())->toBeTrue();
});

test('github target no drift when github updated before push', function () {
    $target = VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'last_pushed_at' => now(),
        'last_seen_github_updated_at' => now()->subHour(),
    ]);

    expect($target->hasDrift())->toBeFalse();
});

test('github target scope for repo', function () {
    VaultSecretGitHubTarget::factory()->count(2)->create([
        'github_repo_id' => $this->repo->id,
    ]);

    $otherRepo = GitHubRepo::factory()->create();
    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $otherRepo->id,
    ]);

    expect(VaultSecretGitHubTarget::forRepo($this->repo->id)->count())->toBe(2);
});

test('github target scope for managed only', function () {
    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'is_managed' => true,
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'is_managed' => false,
    ]);

    expect(VaultSecretGitHubTarget::managed()->count())->toBe(1);
});

test('github target scope for with drift', function () {
    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'drift_status' => 'in_sync',
    ]);

    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'drift_status' => 'modified_on_github',
    ]);

    expect(VaultSecretGitHubTarget::withDrift()->count())->toBe(1);
});

test('github target unique constraint on repo secret and environment', function () {
    VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
        'github_environment_name' => 'staging-env',
    ]);

    expect(fn () => VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $this->repo->id,
        'vault_secret_id' => $this->secret->id,
        'environment' => 'staging',
        'github_environment_name' => 'staging-env',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('github repo has many secret targets', function () {
    VaultSecretGitHubTarget::factory()->count(3)->create([
        'github_repo_id' => $this->repo->id,
    ]);

    expect($this->repo->secretTargets)->toHaveCount(3);
});
