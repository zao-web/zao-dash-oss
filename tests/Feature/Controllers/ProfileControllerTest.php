<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can access profile edit page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('profile.edit'));

    $response->assertStatus(200);
});

test('unauthenticated user cannot access profile edit page', function () {
    $response = $this->get(route('profile.edit'));

    $response->assertRedirect('/login');
});

test('profile edit page displays user information', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'role' => 'admin',
    ]);
    $this->actingAs($user);

    $response = $this->get(route('profile.edit'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Profile/Edit')
        ->has('user')
        ->where('user.name', 'John Doe')
        ->where('user.email', 'john@example.com')
        ->where('user.role', 'admin')
    );
});

test('user can update their profile', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('success', 'Profile updated successfully.');

    $user->refresh();
    expect($user->name)->toBe('New Name');
    expect($user->email)->toBe('new@example.com');
});

test('profile update requires name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'email' => 'test@example.com',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('profile update requires email', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => 'John Doe',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('profile update requires valid email format', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => 'John Doe',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('profile update cannot use email of another user', function () {
    $user1 = User::factory()->create(['email' => 'user1@example.com']);
    $user2 = User::factory()->create(['email' => 'user2@example.com']);

    $this->actingAs($user1);

    $response = $this->put(route('profile.update'), [
        'name' => 'User One',
        'email' => 'user2@example.com',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('user can keep their existing email when updating profile', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'same@example.com',
    ]);
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => 'New Name',
        'email' => 'same@example.com',
    ]);

    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHasNoErrors();
});

test('name cannot exceed 255 characters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => str_repeat('a', 256),
        'email' => 'test@example.com',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('email cannot exceed 255 characters', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->put(route('profile.update'), [
        'name' => 'John Doe',
        'email' => str_repeat('a', 246).'@example.com', // 256 chars total
    ]);

    $response->assertSessionHasErrors(['email']);
});
