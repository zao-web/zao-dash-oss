<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('archives a project via the controller', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->post("/projects/{$project->id}/archive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe(Project::STATUS_ARCHIVED);
});

it('unarchives a project', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create(['status' => 'archived']);

    $this->actingAs($user)
        ->post("/projects/{$project->id}/unarchive")
        ->assertRedirect();

    expect($project->fresh()->status)->toBe(Project::STATUS_ACTIVE);
});

it('archives a client via the controller', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->post("/clients/{$client->id}/archive")
        ->assertRedirect();

    expect($client->fresh()->status)->toBe(Client::STATUS_ARCHIVED);
});

it('unarchives a client', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['status' => 'archived']);

    $this->actingAs($user)
        ->post("/clients/{$client->id}/unarchive")
        ->assertRedirect();

    expect($client->fresh()->status)->toBe(Client::STATUS_ACTIVE);
});

it('notArchived scope hides archived projects', function () {
    Project::factory()->create(['status' => 'active']);
    Project::factory()->create(['status' => 'archived']);

    expect(Project::notArchived()->count())->toBe(1);
});

it('notArchived scope hides archived clients', function () {
    Client::factory()->create(['status' => 'active']);
    Client::factory()->create(['status' => 'archived']);

    expect(Client::notArchived()->count())->toBe(1);
});
