<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Authenticate user for all tests
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('inertia middleware shares authenticated user data', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'role' => 'admin',
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (Assert $page) => $page->has('auth.user')
        ->where('auth.user.id', $user->id)
        ->where('auth.user.name', 'John Doe')
        ->where('auth.user.email', 'john@example.com')
        ->where('auth.user.role', 'admin')
    );
});

test('inertia middleware shares null user when not authenticated', function () {
    auth()->logout();

    $response = $this->get('/login');

    $response->assertInertia(fn (Assert $page) => $page->where('auth.user', null)
    );
});

test('inertia middleware shares user data with different roles', function () {
    $roles = ['owner', 'admin', 'staff', 'client'];

    foreach ($roles as $role) {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page->has('auth.user')
            ->where('auth.user.role', $role)
        );
    }
});

test('inertia middleware includes all required user fields', function () {
    $response = $this->get('/');

    $response->assertInertia(fn (Assert $page) => $page->has('auth.user')
        ->has('auth.user.id')
        ->has('auth.user.name')
        ->has('auth.user.email')
        ->has('auth.user.role')
    );
});

test('inertia middleware does not expose sensitive user fields', function () {
    $response = $this->get('/');

    $response->assertInertia(fn (Assert $page) => $page->has('auth.user')
        ->missing('auth.user.password')
        ->missing('auth.user.remember_token')
    );
});

test('inertia middleware shares data on every inertia request', function () {
    // Test multiple routes that return Inertia responses
    $routes = ['/', '/clients', '/agents'];

    foreach ($routes as $route) {
        $response = $this->get($route);

        if ($response->status() === 200) {
            $response->assertInertia(fn (Assert $page) => $page->has('auth.user')
            );
        }
    }
});

test('inertia middleware preserves parent shared data', function () {
    $response = $this->get('/');

    // Verify parent's shared data like errors and flash messages are preserved
    $response->assertInertia(fn (Assert $page) => $page->has('errors')
    );
});
