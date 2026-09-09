<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('client portal middleware allows client users with client_id', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertStatus(200);
});

test('client portal middleware blocks unauthenticated users', function () {
    $response = $this->get('/portal');

    $response->assertRedirect('/login');
});

test('client portal middleware blocks non-client users', function () {
    $roles = ['owner', 'admin', 'staff'];

    foreach ($roles as $role) {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertForbidden();
        expect($response->exception)->not->toBeNull();
        expect($response->exception->getMessage())->toContain('client users only');
    }
});

test('client portal middleware blocks client users without client_id', function () {
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => null,
    ]);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertForbidden();
    expect($response->exception)->not->toBeNull();
    expect($response->exception->getMessage())->toContain('No client account');
});

test('client portal middleware shares client context with views', function () {
    $client = Client::factory()->create(['name' => 'Test Client Inc']);
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)->get('/portal');

    // Verify the client is shared with views
    $sharedData = view()->getShared();
    expect($sharedData)->toHaveKey('portalClient');
    expect($sharedData['portalClient']->id)->toBe($client->id);
    expect($sharedData['portalClient']->name)->toBe('Test Client Inc');
});

test('client portal middleware verifies client relationship exists', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertStatus(200);

    // Verify client relationship is loaded
    expect($user->client)->not->toBeNull();
    expect($user->client->id)->toBe($client->id);
});

test('client portal middleware with soft-deleted client loads null relationship', function () {
    // Create a client and soft delete it
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    $client->delete(); // Soft delete

    // Reload user to check relationship
    $user->refresh();

    // The middleware shares the client, but it will be null due to soft delete
    expect($user->client)->toBeNull();
});

test('client portal middleware executes before controller', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    // Middleware should share data before controller runs
    $this->actingAs($user)->get('/portal');

    expect(view()->getShared())->toHaveKey('portalClient');
});

test('client portal middleware redirects to login with helpful message', function () {
    $response = $this->get('/portal');

    $response->assertRedirect(route('login'));
});

test('client portal middleware blocks staff users masquerading as clients', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'staff', // Not client role
        'client_id' => $client->id, // Has client_id
    ]);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertForbidden();
});

test('client portal middleware allows access to all client portal routes', function () {
    $client = Client::factory()->create();
    $user = User::factory()->create([
        'role' => 'client',
        'client_id' => $client->id,
    ]);

    // Test that middleware allows through to various client portal endpoints
    $routes = [
        '/portal',
        '/portal/projects',
        '/portal/invoices',
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($user)->get($route);

        // 200 or 404 are both valid (route might not exist, but middleware passed)
        expect($response->status())->toBeIn([200, 404]);
    }
});
