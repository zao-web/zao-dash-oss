<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\VaultSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

test('has correct fillable attributes', function () {
    $expected = [
        'name',
        'key',
        'encrypted_value',
        'category',
        'description',
        'project_id',
        'client_id',
        'allowed_agents',
        'allowed_users',
        'is_sensitive',
        'created_by',
        'updated_by',
        'last_accessed_at',
        'access_count',
        'expires_at',
        'is_active',
    ];

    expect((new VaultSecret)->getFillable())->toEqual($expected);
});

test('casts allowed_agents to array', function () {
    $agents = ['agent-1', 'agent-2'];
    $secret = VaultSecret::factory()->create(['allowed_agents' => $agents]);

    expect($secret->allowed_agents)->toBeArray()
        ->and($secret->allowed_agents)->toHaveCount(2);
});

test('casts allowed_users to array', function () {
    $users = [1, 2, 3];
    $secret = VaultSecret::factory()->create(['allowed_users' => $users]);

    expect($secret->allowed_users)->toBeArray()
        ->and($secret->allowed_users)->toHaveCount(3);
});

test('casts is_sensitive to boolean', function () {
    $secret = VaultSecret::factory()->create(['is_sensitive' => true]);

    expect($secret->is_sensitive)->toBeBool()
        ->and($secret->is_sensitive)->toBeTrue();
});

test('casts is_active to boolean', function () {
    $secret = VaultSecret::factory()->create(['is_active' => true]);

    expect($secret->is_active)->toBeBool()
        ->and($secret->is_active)->toBeTrue();
});

test('casts last_accessed_at to datetime', function () {
    $secret = VaultSecret::factory()->create(['last_accessed_at' => now()]);

    expect($secret->last_accessed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts expires_at to datetime', function () {
    $secret = VaultSecret::factory()->create(['expires_at' => now()->addDays(30)]);

    expect($secret->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts access_count to integer', function () {
    $secret = VaultSecret::factory()->create(['access_count' => 42]);

    expect($secret->access_count)->toBeInt()
        ->and($secret->access_count)->toBe(42);
});

test('hides encrypted_value in array', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->toArray())->not->toHaveKey('encrypted_value');
});

test('belongs to project relationship', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to created by user relationship', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->createdBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to updated by user relationship', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->updatedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many access logs relationship', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret->accessLogs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('active scope filters active secrets', function () {
    VaultSecret::factory()->create(['is_active' => true, 'name' => 'Active Secret']);
    VaultSecret::factory()->create(['is_active' => false, 'name' => 'Inactive Secret']);

    $activeSecrets = VaultSecret::active()->get();

    expect($activeSecrets)->toHaveCount(1)
        ->and($activeSecrets->first()->name)->toBe('Active Secret');
});

test('notExpired scope filters non-expired secrets', function () {
    VaultSecret::factory()->create(['expires_at' => null, 'name' => 'Never Expires']);
    VaultSecret::factory()->create(['expires_at' => now()->addDays(5), 'name' => 'Not Expired']);
    VaultSecret::factory()->create(['expires_at' => now()->subDays(5), 'name' => 'Expired']);

    $notExpiredSecrets = VaultSecret::notExpired()->get();

    expect($notExpiredSecrets)->toHaveCount(2)
        ->and($notExpiredSecrets->pluck('name')->toArray())
        ->toMatchArray(['Never Expires', 'Not Expired']);
});

test('forProject scope filters by project', function () {
    $project = Project::factory()->create();
    VaultSecret::factory()->create(['project_id' => $project->id, 'name' => 'Project Secret']);
    VaultSecret::factory()->create(['project_id' => null, 'name' => 'Other Secret']);

    $projectSecrets = VaultSecret::forProject($project->id)->get();

    expect($projectSecrets)->toHaveCount(1)
        ->and($projectSecrets->first()->name)->toBe('Project Secret');
});

test('forClient scope filters by client', function () {
    $client = Client::factory()->create();
    VaultSecret::factory()->create(['client_id' => $client->id, 'name' => 'Client Secret']);
    VaultSecret::factory()->create(['client_id' => null, 'name' => 'Other Secret']);

    $clientSecrets = VaultSecret::forClient($client->id)->get();

    expect($clientSecrets)->toHaveCount(1)
        ->and($clientSecrets->first()->name)->toBe('Client Secret');
});

test('global scope filters global secrets', function () {
    VaultSecret::factory()->create(['project_id' => null, 'client_id' => null, 'name' => 'Global Secret']);
    VaultSecret::factory()->create(['project_id' => Project::factory(), 'name' => 'Project Secret']);
    VaultSecret::factory()->create(['client_id' => Client::factory(), 'name' => 'Client Secret']);

    $globalSecrets = VaultSecret::global()->get();

    expect($globalSecrets)->toHaveCount(1)
        ->and($globalSecrets->first()->name)->toBe('Global Secret');
});

test('category scope filters by category', function () {
    VaultSecret::factory()->create(['category' => VaultSecret::CATEGORY_API_KEY]);
    VaultSecret::factory()->create(['category' => VaultSecret::CATEGORY_OAUTH]);

    $apiKeySecrets = VaultSecret::category(VaultSecret::CATEGORY_API_KEY)->get();

    expect($apiKeySecrets)->toHaveCount(1)
        ->and($apiKeySecrets->first()->category)->toBe(VaultSecret::CATEGORY_API_KEY);
});

test('is_expired accessor returns true for expired secret', function () {
    $secret = VaultSecret::factory()->create(['expires_at' => now()->subDay()]);

    expect($secret->is_expired)->toBeTrue();
});

test('is_expired accessor returns false for non-expired secret', function () {
    $secret = VaultSecret::factory()->create(['expires_at' => now()->addDay()]);

    expect($secret->is_expired)->toBeFalse();
});

test('is_expired accessor returns false when no expiration', function () {
    $secret = VaultSecret::factory()->create(['expires_at' => null]);

    expect($secret->is_expired)->toBeFalse();
});

test('scope_label accessor returns project label', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $secret = VaultSecret::factory()->create(['project_id' => $project->id]);

    expect($secret->scope_label)->toBe('Project: Test Project');
});

test('scope_label accessor returns client label', function () {
    $client = Client::factory()->create(['name' => 'Test Client']);
    $secret = VaultSecret::factory()->create(['client_id' => $client->id, 'project_id' => null]);

    expect($secret->scope_label)->toBe('Client: Test Client');
});

test('scope_label accessor returns global for no scope', function () {
    $secret = VaultSecret::factory()->create(['project_id' => null, 'client_id' => null]);

    expect($secret->scope_label)->toBe('Global');
});

test('canBeAccessedBy returns false for inactive secret', function () {
    $secret = VaultSecret::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    expect($secret->canBeAccessedBy($user))->toBeFalse();
});

test('canBeAccessedBy returns false for expired secret', function () {
    $secret = VaultSecret::factory()->create(['is_active' => true, 'expires_at' => now()->subDay()]);
    $user = User::factory()->create();

    expect($secret->canBeAccessedBy($user))->toBeFalse();
});

test('canBeAccessedBy returns true for agent with no restrictions', function () {
    $secret = VaultSecret::factory()->create(['is_active' => true, 'allowed_agents' => null]);

    expect($secret->canBeAccessedBy(agentSlug: 'test-agent'))->toBeTrue();
});

test('canBeAccessedBy returns true for allowed agent', function () {
    $secret = VaultSecret::factory()->create([
        'is_active' => true,
        'allowed_agents' => ['agent-1', 'agent-2'],
    ]);

    expect($secret->canBeAccessedBy(agentSlug: 'agent-1'))->toBeTrue();
});

test('canBeAccessedBy returns false for disallowed agent', function () {
    $secret = VaultSecret::factory()->create([
        'is_active' => true,
        'allowed_agents' => ['agent-1', 'agent-2'],
    ]);

    expect($secret->canBeAccessedBy(agentSlug: 'agent-3'))->toBeFalse();
});

test('canBeAccessedBy returns true for admin user', function () {
    $secret = VaultSecret::factory()->create(['is_active' => true]);
    $user = User::factory()->create(['is_admin' => true]);

    expect($secret->canBeAccessedBy($user))->toBeTrue();
});

test('canBeAccessedBy returns true for user with no restrictions', function () {
    $secret = VaultSecret::factory()->create(['is_active' => true, 'allowed_users' => null]);
    $user = User::factory()->create(['is_admin' => false]);

    expect($secret->canBeAccessedBy($user))->toBeTrue();
});

test('canBeAccessedBy returns true for allowed user', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $secret = VaultSecret::factory()->create([
        'is_active' => true,
        'allowed_users' => [$user->id],
    ]);

    expect($secret->canBeAccessedBy($user))->toBeTrue();
});

test('canBeAccessedBy returns false for disallowed user', function () {
    $user1 = User::factory()->create(['is_admin' => false]);
    $user2 = User::factory()->create(['is_admin' => false]);
    $secret = VaultSecret::factory()->create([
        'is_active' => true,
        'allowed_users' => [$user1->id],
    ]);

    expect($secret->canBeAccessedBy($user2))->toBeFalse();
});

test('setValueAttribute encrypts value', function () {
    $secret = new VaultSecret;
    $plainValue = 'super-secret-value';

    $secret->value = $plainValue;

    expect($secret->attributes['encrypted_value'])->not->toBe($plainValue)
        ->and(Crypt::decryptString($secret->attributes['encrypted_value']))->toBe($plainValue);
});

test('getDecryptedValue returns decrypted value', function () {
    $secret = VaultSecret::factory()->create();
    $plainValue = 'test-secret-123';

    $secret->value = $plainValue;
    $secret->save();
    $secret->refresh();

    expect($secret->getDecryptedValue())->toBe($plainValue);
});

test('has correct category constants', function () {
    expect(VaultSecret::CATEGORY_API_KEY)->toBe('api_key')
        ->and(VaultSecret::CATEGORY_OAUTH)->toBe('oauth')
        ->and(VaultSecret::CATEGORY_CREDENTIAL)->toBe('credential')
        ->and(VaultSecret::CATEGORY_CERTIFICATE)->toBe('certificate')
        ->and(VaultSecret::CATEGORY_OTHER)->toBe('other');
});

test('can be created via factory', function () {
    $secret = VaultSecret::factory()->create();

    expect($secret)->toBeInstanceOf(VaultSecret::class)
        ->and($secret->exists)->toBeTrue();
});
