<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Client;
use App\Models\DeploymentConfig;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new DeploymentConfig)->getGuarded())->toBe(['*']);
});

test('casts secrets to array', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'secrets' => ['SFTP_HOST' => 'example.com', 'SFTP_USER' => 'deploy'],
        'onboarding_completed' => false,
    ]);

    expect($config->secrets)->toBeArray()
        ->and($config->secrets)->toHaveKey('SFTP_HOST');
});

test('casts onboarding_completed to boolean', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'vercel',
        'onboarding_completed' => true,
    ]);

    expect($config->onboarding_completed)->toBeTrue();
});

test('belongs to client relationship', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'netlify',
        'onboarding_completed' => false,
    ]);

    expect($config->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to repo relationship', function () {
    $client = Client::factory()->create();
    $installation = GitHubInstallation::create([
        'installation_id' => '12345',
        'account_login' => 'testuser',
        'account_type' => 'User',
    ]);

    $repo = GitHubRepo::create([
        'github_installation_id' => $installation->id,
        'repo_id' => '67890',
        'name' => 'test-repo',
        'full_name' => 'testuser/test-repo',
        'private' => true,
    ]);

    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'onboarding_completed' => false,
    ]);

    expect($config->repo())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to onboarding agent run relationship', function () {
    $client = Client::factory()->create();
    $agent = Agent::factory()->create();
    $run = AgentRun::create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'started_at' => now(),
    ]);

    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_agent_run_id' => $run->id,
        'onboarding_completed' => false,
    ]);

    expect($config->onboardingAgentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isSftp returns true for sftp deployment type', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_completed' => false,
    ]);

    expect($config->isSftp())->toBeTrue();
});

test('isSftp returns false for non-sftp deployment type', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'vercel',
        'onboarding_completed' => false,
    ]);

    expect($config->isSftp())->toBeFalse();
});

test('isVercel returns true for vercel deployment type', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'vercel',
        'onboarding_completed' => false,
    ]);

    expect($config->isVercel())->toBeTrue();
});

test('isNetlify returns true for netlify deployment type', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'netlify',
        'onboarding_completed' => false,
    ]);

    expect($config->isNetlify())->toBeTrue();
});

test('needsOnboarding returns true when not completed', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_completed' => false,
    ]);

    expect($config->needsOnboarding())->toBeTrue();
});

test('needsOnboarding returns false when completed', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_completed' => true,
    ]);

    expect($config->needsOnboarding())->toBeFalse();
});

test('getWorkflowTemplatePath returns correct path for sftp', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_completed' => false,
    ]);

    expect($config->getWorkflowTemplatePath())->toBe('sftp-deploy.yml');
});

test('getWorkflowTemplatePath returns correct path for vercel', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'vercel',
        'onboarding_completed' => false,
    ]);

    expect($config->getWorkflowTemplatePath())->toBe('vercel-deploy.yml');
});

test('getWorkflowTemplatePath returns correct path for netlify', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'netlify',
        'onboarding_completed' => false,
    ]);

    expect($config->getWorkflowTemplatePath())->toBe('netlify-deploy.yml');
});

test('getWorkflowTemplatePath returns correct path for wordpress', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'wordpress',
        'onboarding_completed' => false,
    ]);

    expect($config->getWorkflowTemplatePath())->toBe('wordpress-deploy.yml');
});

test('getWorkflowTemplatePath returns default path for unknown type', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'custom',
        'onboarding_completed' => false,
    ]);

    expect($config->getWorkflowTemplatePath())->toBe('generic-deploy.yml');
});

test('can be created', function () {
    $client = Client::factory()->create();
    $config = DeploymentConfig::create([
        'client_id' => $client->id,
        'deployment_type' => 'sftp',
        'onboarding_completed' => false,
    ]);

    expect($config)->toBeInstanceOf(DeploymentConfig::class)
        ->and($config->exists)->toBeTrue();
});
