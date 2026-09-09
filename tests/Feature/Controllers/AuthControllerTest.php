<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login page can be accessed', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('user can login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

test('user cannot login with invalid password', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('user cannot login with non-existent email', function () {
    $response = $this->post('/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
    $this->assertGuest();
});

test('login requires email', function () {
    $response = $this->post('/login', [
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('login requires password', function () {
    $response = $this->post('/login', [
        'email' => 'test@example.com',
    ]);

    $response->assertSessionHasErrors(['password']);
});

test('email must be valid email format', function () {
    $response = $this->post('/login', [
        'email' => 'not-an-email',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('remember me functionality works', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
        'remember' => true,
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

test('user can logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

test('logout invalidates session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $oldSessionToken = session()->token();

    $this->post('/logout');

    expect(session()->token())->not->toBe($oldSessionToken);
});

test('logout regenerates csrf token', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post('/logout');

    $response->assertSessionHasNoErrors();
});
