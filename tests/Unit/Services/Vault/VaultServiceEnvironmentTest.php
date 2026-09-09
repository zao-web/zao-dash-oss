<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\VaultSecret;
use App\Models\VaultSecretValue;
use App\Services\Vault\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->vault = new VaultService;

    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);

    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
    ]);
});

test('get secret for specific environment', function () {
    $secret = VaultSecret::factory()->create([
        'key' => 'FTP_PASSWORD',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('prod-password'),
        'is_active' => true,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('staging-password'),
        'is_active' => true,
    ]);

    $prodValue = $this->vault->getForEnvironment('FTP_PASSWORD', 'production', $this->project->id);
    $stagingValue = $this->vault->getForEnvironment('FTP_PASSWORD', 'staging', $this->project->id);

    expect($prodValue)->toBe('prod-password')
        ->and($stagingValue)->toBe('staging-password');
});

test('get secret falls back to default environment when specific not found', function () {
    $secret = VaultSecret::factory()->create([
        'key' => 'API_KEY',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => null,
        'encrypted_value' => Crypt::encryptString('default-api-key'),
        'is_active' => true,
    ]);

    $value = $this->vault->getForEnvironment('API_KEY', 'staging', $this->project->id);

    expect($value)->toBe('default-api-key');
});

test('get secret returns null when no value for environment and no default', function () {
    $secret = VaultSecret::factory()->create([
        'key' => 'SPECIFIC_KEY',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'production',
        'encrypted_value' => Crypt::encryptString('prod-only'),
        'is_active' => true,
    ]);

    $value = $this->vault->getForEnvironment('SPECIFIC_KEY', 'staging', $this->project->id);

    expect($value)->toBeNull();
});

test('secret resolution precedence: project > client > global', function () {
    $globalSecret = VaultSecret::factory()->create([
        'key' => 'SHARED_KEY',
        'project_id' => null,
        'client_id' => null,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $globalSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('global-value'),
        'is_active' => true,
    ]);

    $clientSecret = VaultSecret::factory()->create([
        'key' => 'SHARED_KEY',
        'project_id' => null,
        'client_id' => $this->client->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $clientSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('client-value'),
        'is_active' => true,
    ]);

    $projectSecret = VaultSecret::factory()->create([
        'key' => 'SHARED_KEY',
        'project_id' => $this->project->id,
        'client_id' => null,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $projectSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('project-value'),
        'is_active' => true,
    ]);

    $value = $this->vault->getForEnvironment(
        'SHARED_KEY',
        'staging',
        $this->project->id,
        $this->client->id
    );

    expect($value)->toBe('project-value');
});

test('secret resolution falls to client when no project secret', function () {
    $globalSecret = VaultSecret::factory()->create([
        'key' => 'CLIENT_KEY',
        'project_id' => null,
        'client_id' => null,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $globalSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('global-value'),
        'is_active' => true,
    ]);

    $clientSecret = VaultSecret::factory()->create([
        'key' => 'CLIENT_KEY',
        'project_id' => null,
        'client_id' => $this->client->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $clientSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('client-value'),
        'is_active' => true,
    ]);

    $value = $this->vault->getForEnvironment(
        'CLIENT_KEY',
        'staging',
        $this->project->id,
        $this->client->id
    );

    expect($value)->toBe('client-value');
});

test('secret resolution falls to global when no project or client secret', function () {
    $globalSecret = VaultSecret::factory()->create([
        'key' => 'GLOBAL_KEY',
        'project_id' => null,
        'client_id' => null,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $globalSecret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('global-value'),
        'is_active' => true,
    ]);

    $value = $this->vault->getForEnvironment(
        'GLOBAL_KEY',
        'staging',
        $this->project->id,
        $this->client->id
    );

    expect($value)->toBe('global-value');
});

test('store secret with environment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $secret = $this->vault->storeWithEnvironment(
        key: 'NEW_SECRET',
        value: 'secret-value',
        name: 'New Secret',
        environment: 'staging',
        options: [
            'project_id' => $this->project->id,
            'category' => 'credential',
        ]
    );

    expect($secret)->toBeInstanceOf(VaultSecret::class);

    $this->assertDatabaseHas('vault_secrets', [
        'key' => 'NEW_SECRET',
        'project_id' => $this->project->id,
    ]);

    $this->assertDatabaseHas('vault_secret_values', [
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
    ]);

    $storedValue = $this->vault->getForEnvironment('NEW_SECRET', 'staging', $this->project->id);
    expect($storedValue)->toBe('secret-value');
});

test('store multiple environment values for same secret', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $secret = $this->vault->storeWithEnvironment(
        key: 'MULTI_ENV_SECRET',
        value: 'staging-value',
        name: 'Multi Env Secret',
        environment: 'staging',
        options: ['project_id' => $this->project->id]
    );

    $this->vault->addEnvironmentValue($secret, 'production', 'prod-value');
    $this->vault->addEnvironmentValue($secret, null, 'default-value');

    expect($secret->values)->toHaveCount(3);

    $stagingValue = $this->vault->getForEnvironment('MULTI_ENV_SECRET', 'staging', $this->project->id);
    $prodValue = $this->vault->getForEnvironment('MULTI_ENV_SECRET', 'production', $this->project->id);
    $defaultValue = $this->vault->getForEnvironment('MULTI_ENV_SECRET', 'development', $this->project->id);

    expect($stagingValue)->toBe('staging-value')
        ->and($prodValue)->toBe('prod-value')
        ->and($defaultValue)->toBe('default-value');
});

test('get agent secrets for environment', function () {
    $secret1 = VaultSecret::factory()->create([
        'key' => 'AGENT_SECRET_1',
        'project_id' => $this->project->id,
        'allowed_agents' => ['deploy-agent'],
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret1->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('agent-staging-1'),
        'is_active' => true,
    ]);

    $secret2 = VaultSecret::factory()->create([
        'key' => 'AGENT_SECRET_2',
        'project_id' => $this->project->id,
        'allowed_agents' => ['deploy-agent'],
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret2->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('agent-staging-2'),
        'is_active' => true,
    ]);

    $secrets = $this->vault->getAgentSecretsForEnvironment(
        agentSlug: 'deploy-agent',
        environment: 'staging',
        projectId: $this->project->id
    );

    expect($secrets)->toHaveCount(2)
        ->and($secrets['AGENT_SECRET_1'])->toBe('agent-staging-1')
        ->and($secrets['AGENT_SECRET_2'])->toBe('agent-staging-2');
});

test('list secrets shows environment info', function () {
    $secret = VaultSecret::factory()->create([
        'key' => 'LIST_TEST',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'production',
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
    ]);

    $list = $this->vault->listWithEnvironments($this->project->id);

    expect($list)->toHaveCount(1)
        ->and($list[0]['environments'])->toContain('production', 'staging');
});

test('check if secret exists for environment', function () {
    $secret = VaultSecret::factory()->create([
        'key' => 'EXISTS_CHECK',
        'project_id' => $this->project->id,
    ]);

    VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'is_active' => true,
    ]);

    expect($this->vault->existsForEnvironment('EXISTS_CHECK', 'staging', $this->project->id))->toBeTrue()
        ->and($this->vault->existsForEnvironment('EXISTS_CHECK', 'production', $this->project->id))->toBeFalse()
        ->and($this->vault->existsForEnvironment('NONEXISTENT', 'staging', $this->project->id))->toBeFalse();
});

test('rotate secret value for specific environment', function () {
    $user = User::factory()->create();

    $secret = VaultSecret::factory()->create([
        'key' => 'ROTATE_TEST',
        'project_id' => $this->project->id,
    ]);

    $value = VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => Crypt::encryptString('old-value'),
        'is_active' => true,
    ]);

    $this->vault->rotateForEnvironment($secret, 'staging', 'new-value', $user);

    $newValue = $this->vault->getForEnvironment('ROTATE_TEST', 'staging', $this->project->id);
    expect($newValue)->toBe('new-value');
});
