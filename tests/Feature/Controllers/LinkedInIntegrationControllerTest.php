<?php

use App\Models\LinkedInCredential;
use App\Models\User;
use App\Services\LinkedIn\LinkedInService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->linkedInService = Mockery::mock(LinkedInService::class);
    $this->app->instance(LinkedInService::class, $this->linkedInService);
});

// OAuth redirect tests
test('redirect generates linkedin oauth url', function () {
    $authUrl = 'https://www.linkedin.com/oauth/v2/authorization?client_id=123';

    $this->linkedInService->shouldReceive('getAuthUrl')
        ->once()
        ->with(route('linkedin.callback'))
        ->andReturn($authUrl);

    $response = $this->get(route('linkedin.redirect'));

    $response->assertRedirect($authUrl);
});

// OAuth callback tests
test('callback handles oauth error', function () {
    $response = $this->get(route('linkedin.callback', [
        'error' => 'access_denied',
        'error_description' => 'User denied access',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('User denied access');
});

test('callback exchanges code for tokens and stores credential', function () {
    $tokenData = [
        'access_token' => 'linkedin-access-token',
        'refresh_token' => 'linkedin-refresh-token',
        'expires_in' => 3600,
        'scope' => 'openid profile email w_member_social',
    ];

    $profile = [
        'sub' => 'linkedin-user-id',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'picture' => 'https://example.com/photo.jpg',
    ];

    $this->linkedInService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->with('auth-code', route('linkedin.callback'))
        ->andReturn($tokenData);

    $this->linkedInService->shouldReceive('getProfile')
        ->once()
        ->andReturn($profile);

    $response = $this->get(route('linkedin.callback', [
        'code' => 'auth-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('success', 'LinkedIn connected successfully!');

    $credential = LinkedInCredential::where('user_id', $this->user->id)->first();
    expect($credential)->not->toBeNull();
    expect($credential->linkedin_id)->toBe('linkedin-user-id');
    expect($credential->name)->toBe('John Doe');
    expect($credential->email)->toBe('john@example.com');
});

test('callback handles token exchange failure', function () {
    $this->linkedInService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->andThrow(new Exception('Invalid authorization code'));

    $response = $this->get(route('linkedin.callback', [
        'code' => 'invalid-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('Failed to connect LinkedIn');
});

test('callback updates existing credential', function () {
    LinkedInCredential::factory()->for($this->user)->create([
        'linkedin_id' => 'linkedin-user-id',
        'name' => 'Old Name',
    ]);

    $tokenData = [
        'access_token' => 'new-token',
        'refresh_token' => 'new-refresh',
        'expires_in' => 3600,
        'scope' => 'openid profile email',
    ];

    $profile = [
        'sub' => 'linkedin-user-id',
        'name' => 'New Name',
        'email' => 'newemail@example.com',
    ];

    $this->linkedInService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->andReturn($tokenData);

    $this->linkedInService->shouldReceive('getProfile')
        ->once()
        ->andReturn($profile);

    $response = $this->get(route('linkedin.callback', [
        'code' => 'auth-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));

    expect(LinkedInCredential::where('user_id', $this->user->id)->count())->toBe(1);
    $credential = LinkedInCredential::where('user_id', $this->user->id)->first();
    expect($credential->name)->toBe('New Name');
});

// Status tests
test('status returns disconnected when no credential', function () {
    $response = $this->getJson(route('linkedin.status'));

    $response->assertStatus(200);
    $response->assertJson(['connected' => false]);
});

test('status returns connected with credential details', function () {
    LinkedInCredential::factory()->for($this->user)->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'profile_picture' => 'https://example.com/photo.jpg',
        'organization_name' => 'ACME Corp',
        'is_active' => true,
        'token_expires_at' => now()->addMonth(),
    ]);

    $response = $this->getJson(route('linkedin.status'));

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'organization_name' => 'ACME Corp',
        'is_active' => true,
    ]);
});

test('status requires authentication', function () {
    auth()->logout();

    $response = $this->getJson(route('linkedin.status'));

    $response->assertStatus(401);
});

// Disconnect tests
test('disconnect removes credential', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create();

    $response = $this->deleteJson(route('linkedin.disconnect'));

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'LinkedIn disconnected');
    expect(LinkedInCredential::find($credential->id))->toBeNull();
});

test('disconnect handles missing credential gracefully', function () {
    $response = $this->deleteJson(route('linkedin.disconnect'));

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'LinkedIn disconnected');
});

// Create post tests
test('create post requires credential', function () {
    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Test post',
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'LinkedIn not connected');
});

test('create post validates required fields', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('linkedin.createPost'), []);

    $response->assertStatus(422);
});

test('create post creates text post', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create();

    $result = ['id' => 'post123'];

    $this->linkedInService->shouldReceive('createTextPost')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            'Hello LinkedIn!'
        )
        ->andReturn($result);

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Hello LinkedIn!',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('post_id', 'post123');
});

test('create post creates article post with url', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create();

    $result = ['id' => 'post456'];

    $this->linkedInService->shouldReceive('createArticlePost')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            'Check out this article',
            'https://example.com/article',
            'Article Title',
            'Article description'
        )
        ->andReturn($result);

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Check out this article',
        'url' => 'https://example.com/article',
        'title' => 'Article Title',
        'description' => 'Article description',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('post_id', 'post456');
});

test('create post creates organization post when requested', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create([
        'organization_id' => 'org123',
        'organization_name' => 'ACME Corp',
    ]);

    $result = ['id' => 'post789'];

    $this->linkedInService->shouldReceive('createOrganizationPost')
        ->once()
        ->andReturn($result);

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Company announcement',
        'as_organization' => true,
    ]);

    $response->assertStatus(200);
});

test('create post validates url when provided', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Test',
        'url' => 'not-a-url',
    ]);

    $response->assertStatus(422);
});

test('create post requires title when url provided', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Test',
        'url' => 'https://example.com',
    ]);

    $response->assertStatus(422);
});

test('create post handles api errors', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $this->linkedInService->shouldReceive('createTextPost')
        ->once()
        ->andThrow(new Exception('LinkedIn API error'));

    $response = $this->postJson(route('linkedin.createPost'), [
        'text' => 'Test post',
    ]);

    $response->assertStatus(500);
    expect($response->json('error'))->toContain('Failed to create post');
});

// Get organizations tests
test('get organizations requires credential', function () {
    $response = $this->getJson(route('linkedin.getOrganizations'));

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'LinkedIn not connected');
});

test('get organizations returns user organizations', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create();

    $organizations = [
        ['id' => 'org1', 'name' => 'Company 1'],
        ['id' => 'org2', 'name' => 'Company 2'],
    ];

    $this->linkedInService->shouldReceive('getOrganizations')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $credential->id))
        ->andReturn($organizations);

    $response = $this->getJson(route('linkedin.getOrganizations'));

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'organizations');
});

test('get organizations handles api errors', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $this->linkedInService->shouldReceive('getOrganizations')
        ->once()
        ->andThrow(new Exception('API error'));

    $response = $this->getJson(route('linkedin.getOrganizations'));

    $response->assertStatus(500);
});

// Set organization tests
test('set organization requires credential', function () {
    $response = $this->postJson(route('linkedin.setOrganization'), [
        'organization_id' => 'org123',
        'organization_name' => 'ACME Corp',
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'LinkedIn not connected');
});

test('set organization validates required fields', function () {
    LinkedInCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('linkedin.setOrganization'), [
        'organization_id' => 'org123',
    ]);

    $response->assertStatus(422);
});

test('set organization updates credential', function () {
    $credential = LinkedInCredential::factory()->for($this->user)->create([
        'organization_id' => null,
        'organization_name' => null,
    ]);

    $response = $this->postJson(route('linkedin.setOrganization'), [
        'organization_id' => 'org123',
        'organization_name' => 'ACME Corp',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'Organization set successfully');

    expect($credential->fresh()->organization_id)->toBe('org123');
    expect($credential->fresh()->organization_name)->toBe('ACME Corp');
});
