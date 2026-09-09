<?php

use App\Models\GitHubRepo;
use App\Models\GitHubWorkflowSecretRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->repo = GitHubRepo::factory()->create([
        'full_name' => 'zao-web/sierra-theme',
    ]);
});

test('workflow requirement belongs to repo', function () {
    $requirement = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'FTP_PASSWORD',
    ]);

    expect($requirement->repo)->toBeInstanceOf(GitHubRepo::class)
        ->and($requirement->repo->id)->toBe($this->repo->id);
});

test('workflow requirement stores workflow path', function () {
    $requirement = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy-staging.yml',
        'secret_name' => 'AWS_ACCESS_KEY',
    ]);

    expect($requirement->workflow_path)->toBe('.github/workflows/deploy-staging.yml');
});

test('workflow requirement stores secret name', function () {
    $requirement = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'DATABASE_PASSWORD',
    ]);

    expect($requirement->secret_name)->toBe('DATABASE_PASSWORD');
});

test('workflow requirement tracks if required', function () {
    $required = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'REQUIRED_SECRET',
        'is_required' => true,
    ]);

    $optional = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'OPTIONAL_SECRET',
        'is_required' => false,
    ]);

    expect($required->is_required)->toBeTrue()
        ->and($optional->is_required)->toBeFalse();
});

test('workflow requirement stores job environment name', function () {
    $requirement = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'PROD_API_KEY',
        'job_environment_name' => 'production',
    ]);

    expect($requirement->job_environment_name)->toBe('production');
});

test('workflow requirement tracks source', function () {
    $parsed = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'source' => 'parsed',
    ]);

    $manual = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'source' => 'manual',
    ]);

    $inferred = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'source' => 'agent_inferred',
    ]);

    expect($parsed->source)->toBe('parsed')
        ->and($manual->source)->toBe('manual')
        ->and($inferred->source)->toBe('agent_inferred');
});

test('workflow requirement tracks detected at timestamp', function () {
    $requirement = GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'detected_at' => now(),
    ]);

    expect($requirement->detected_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('workflow requirement scope for repo', function () {
    GitHubWorkflowSecretRequirement::factory()->count(3)->create([
        'github_repo_id' => $this->repo->id,
    ]);

    $otherRepo = GitHubRepo::factory()->create();
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $otherRepo->id,
    ]);

    expect(GitHubWorkflowSecretRequirement::forRepo($this->repo->id)->count())->toBe(3);
});

test('workflow requirement scope for workflow', function () {
    GitHubWorkflowSecretRequirement::factory()->count(2)->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/test.yml',
    ]);

    expect(GitHubWorkflowSecretRequirement::forWorkflow('.github/workflows/deploy.yml')->count())->toBe(2);
});

test('workflow requirement scope for environment', function () {
    GitHubWorkflowSecretRequirement::factory()->count(2)->create([
        'github_repo_id' => $this->repo->id,
        'job_environment_name' => 'staging',
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'job_environment_name' => 'production',
    ]);

    expect(GitHubWorkflowSecretRequirement::forEnvironment('staging')->count())->toBe(2);
});

test('workflow requirement scope for required only', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'is_required' => true,
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'is_required' => false,
    ]);

    expect(GitHubWorkflowSecretRequirement::required()->count())->toBe(1);
});

test('workflow requirement unique constraint on repo workflow and secret', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'FTP_PASSWORD',
        'job_environment_name' => 'production',
    ]);

    expect(fn () => GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'FTP_PASSWORD',
        'job_environment_name' => 'production',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('github repo has many workflow requirements', function () {
    GitHubWorkflowSecretRequirement::factory()->count(5)->create([
        'github_repo_id' => $this->repo->id,
    ]);

    expect($this->repo->workflowSecretRequirements)->toHaveCount(5);
});

test('workflow requirement can get all unique secret names for repo', function () {
    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'FTP_PASSWORD',
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'AWS_KEY',
    ]);

    GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $this->repo->id,
        'secret_name' => 'FTP_PASSWORD',
        'workflow_path' => '.github/workflows/other.yml',
    ]);

    $secretNames = GitHubWorkflowSecretRequirement::forRepo($this->repo->id)
        ->pluck('secret_name')
        ->unique()
        ->values();

    expect($secretNames)->toHaveCount(2)
        ->and($secretNames->toArray())->toContain('FTP_PASSWORD', 'AWS_KEY');
});
