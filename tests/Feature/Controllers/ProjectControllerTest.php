<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create project', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'description' => 'Test Description',
        'status' => 'active',
        'type' => 'retainer',
        'budget' => 5000,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Project created successfully.');

    $this->assertDatabaseHas('projects', [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'description' => 'Test Description',
        'status' => 'active',
        'type' => 'retainer',
        'budget' => 5000,
        'slug' => 'test-project',
    ]);
});

test('project creation requires client_id', function () {
    $response = $this->post(route('projects.store'), [
        'name' => 'Test Project',
    ]);

    $response->assertSessionHasErrors(['client_id']);
});

test('project creation requires valid client_id', function () {
    $response = $this->post(route('projects.store'), [
        'client_id' => 99999,
        'name' => 'Test Project',
    ]);

    $response->assertSessionHasErrors(['client_id']);
});

test('project creation requires name', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('project status must be valid', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('project type must be valid', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'type' => 'invalid-type',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('project budget must be numeric', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'budget' => 'not-a-number',
    ]);

    $response->assertSessionHasErrors(['budget']);
});

test('project budget cannot be negative', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
        'budget' => -100,
    ]);

    $response->assertSessionHasErrors(['budget']);
});

test('can update project', function () {
    $project = Project::factory()->create([
        'name' => 'Old Name',
        'status' => 'active',
    ]);

    $response = $this->put(route('projects.update', $project), [
        'name' => 'New Name',
        'description' => 'Updated Description',
        'status' => 'on_hold',
        'type' => 'project',
        'budget' => 10000,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Project updated successfully.');

    $project->refresh();
    expect($project->name)->toBe('New Name');
    expect($project->status)->toBe('on_hold');
    expect($project->budget)->toBe(10000.0);
});

test('updating project name updates slug', function () {
    $project = Project::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $this->put(route('projects.update', $project), [
        'name' => 'New Name',
        'description' => 'Description',
        'status' => 'active',
    ]);

    $project->refresh();
    expect($project->slug)->toBe('new-name');
});

test('can delete project', function () {
    $project = Project::factory()->create();

    $response = $this->delete(route('projects.destroy', $project));

    $response->assertRedirect(route('projects.index'));
    $response->assertSessionHas('success', 'Project deleted successfully.');

    $this->assertDatabaseMissing('projects', [
        'id' => $project->id,
    ]);
});

test('can update project status', function () {
    $project = Project::factory()->create(['status' => 'active']);

    $response = $this->put(route('projects.updateStatus', $project), [
        'status' => 'completed',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Project status updated successfully.');

    $project->refresh();
    expect($project->status)->toBe('completed');
});

test('update status requires valid status', function () {
    $project = Project::factory()->create();

    $response = $this->put(route('projects.updateStatus', $project), [
        'status' => 'invalid',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('unauthenticated user cannot create project', function () {
    auth()->logout();
    $client = Client::factory()->create();

    $response = $this->post(route('projects.store'), [
        'client_id' => $client->id,
        'name' => 'Test Project',
    ]);

    $response->assertRedirect('/login');
});

test('unauthenticated user cannot update project', function () {
    auth()->logout();
    $project = Project::factory()->create();

    $response = $this->put(route('projects.update', $project), [
        'name' => 'New Name',
    ]);

    $response->assertRedirect('/login');
});

test('unauthenticated user cannot delete project', function () {
    auth()->logout();
    $project = Project::factory()->create();

    $response = $this->delete(route('projects.destroy', $project));

    $response->assertRedirect('/login');
});
