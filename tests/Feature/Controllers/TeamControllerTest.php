<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

test('can create team member', function () {
    $response = $this->post(route('team.store'), [
        'name' => 'New Team Member',
        'email' => 'newmember@example.com',
        'role' => 'staff',
        'title' => 'Developer',
        'department' => 'Engineering',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Team member invited successfully.');

    $this->assertDatabaseHas('users', [
        'name' => 'New Team Member',
        'email' => 'newmember@example.com',
        'role' => 'staff',
        'title' => 'Developer',
    ]);
});

test('team member creation requires name', function () {
    $response = $this->post(route('team.store'), [
        'email' => 'test@example.com',
        'role' => 'staff',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('team member creation requires email', function () {
    $response = $this->post(route('team.store'), [
        'name' => 'Test User',
        'role' => 'staff',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('team member creation requires unique email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->post(route('team.store'), [
        'name' => 'Test User',
        'email' => 'existing@example.com',
        'role' => 'staff',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('team member role must be valid', function () {
    $response = $this->post(route('team.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'role' => 'invalid-role',
    ]);

    $response->assertSessionHasErrors(['role']);
});

test('can update team member', function () {
    $member = User::factory()->create([
        'name' => 'Old Name',
        'role' => 'staff',
    ]);

    $response = $this->put(route('team.update', $member), [
        'name' => 'New Name',
        'email' => $member->email,
        'role' => 'admin',
        'title' => 'Senior Developer',
        'department' => 'Engineering',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Team member updated successfully.');

    $member->refresh();
    expect($member->name)->toBe('New Name');
    expect($member->role)->toBe('admin');
});

test('can update only role', function () {
    $member = User::factory()->create(['role' => 'staff']);

    $response = $this->put(route('team.update', $member), [
        'role' => 'admin',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Role updated successfully.');

    $member->refresh();
    expect($member->role)->toBe('admin');
});

test('can update team member with permissions', function () {
    $member = User::factory()->create(['role' => 'staff']);

    $response = $this->put(route('team.update', $member), [
        'name' => $member->name,
        'email' => $member->email,
        'role' => 'staff',
        'permissions' => [
            'manage_clients' => true,
            'manage_projects' => true,
            'view_vault' => false,
        ],
    ]);

    $response->assertRedirect();

    $member->refresh();
    expect($member->permissions)->toHaveKey('manage_clients');
    expect($member->permissions['manage_clients'])->toBeTrue();
});

test('can delete team member', function () {
    $member = User::factory()->create();

    $response = $this->delete(route('team.destroy', $member));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Team member removed successfully.');

    $this->assertDatabaseMissing('users', [
        'id' => $member->id,
    ]);
});

test('cannot delete yourself', function () {
    $response = $this->delete(route('team.destroy', $this->user));

    $response->assertRedirect();
    $response->assertSessionHasErrors(['error']);

    $this->assertDatabaseHas('users', [
        'id' => $this->user->id,
    ]);
});

test('deleting team member unassigns their tasks', function () {
    $member = User::factory()->create();
    $task = \App\Models\Task::factory()->create(['assigned_to' => $member->id]);

    $this->delete(route('team.destroy', $member));

    $task->refresh();
    expect($task->assigned_to)->toBeNull();
});

test('email must be unique when updating', function () {
    $member1 = User::factory()->create(['email' => 'user1@example.com']);
    $member2 = User::factory()->create(['email' => 'user2@example.com']);

    $response = $this->put(route('team.update', $member1), [
        'name' => $member1->name,
        'email' => 'user2@example.com',
        'role' => $member1->role,
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('can keep same email when updating', function () {
    $member = User::factory()->create(['email' => 'test@example.com']);

    $response = $this->put(route('team.update', $member), [
        'name' => 'Updated Name',
        'email' => 'test@example.com',
        'role' => $member->role,
    ]);

    $response->assertSessionHasNoErrors();
});
