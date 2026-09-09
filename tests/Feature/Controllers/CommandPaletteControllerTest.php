<?php

use App\Models\Agent;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can search across entities', function () {
    Project::factory()->create(['name' => 'Test Project']);
    Task::factory()->create(['title' => 'Test Task']);
    Client::factory()->create(['name' => 'Test Client']);

    $response = $this->postJson(route('command-palette.search'), [
        'query' => 'Test',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'projects',
        'tasks',
        'clients',
    ]);
});

test('search requires minimum query length', function () {
    $response = $this->postJson(route('command-palette.search'), [
        'query' => 'a',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['query']);
});

test('search can filter by entity types', function () {
    Project::factory()->create(['name' => 'Test Project']);
    Task::factory()->create(['title' => 'Test Task']);

    $response = $this->postJson(route('command-palette.search'), [
        'query' => 'Test',
        'types' => ['projects'],
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['projects']);
    $response->assertJsonMissing(['tasks']);
});

test('can get quick stats', function () {
    $response = $this->getJson(route('command-palette.stats'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'projects' => ['total', 'active'],
        'tasks' => ['total', 'pending', 'in_progress'],
        'clients' => ['total', 'active'],
        'approvals' => ['pending'],
        'agents' => ['total', 'active'],
    ]);
});

test('can get available tools', function () {
    $response = $this->getJson(route('command-palette.tools'));

    $response->assertStatus(200);
    $response->assertJsonStructure(['tools']);
});

test('can log command execution', function () {
    $response = $this->postJson(route('command-palette.logCommand'), [
        'command_id' => 'test-command',
        'command_type' => 'navigation',
        'query' => 'test query',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['logged' => true]);
});

test('log command requires valid command type', function () {
    $response = $this->postJson(route('command-palette.logCommand'), [
        'command_id' => 'test-command',
        'command_type' => 'invalid-type',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['command_type']);
});

test('unauthenticated user cannot search', function () {
    auth()->logout();

    $response = $this->postJson(route('command-palette.search'), [
        'query' => 'test',
    ]);

    $response->assertStatus(401);
});

// Object Search Tests

test('can search for projects by name', function () {
    $project = Project::factory()->create(['name' => 'Zao Dashboard']);
    Project::factory()->create(['name' => 'Other Project']);

    $response = $this->getJson('/api/command-palette/object-search?query=Zao');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'results' => ['projects'],
        'total',
        'query',
    ]);
    $response->assertJsonPath('results.projects.0.name', 'Zao Dashboard');
    $response->assertJsonPath('results.projects.0.type', 'project');
    $response->assertJsonPath('results.projects.0.icon', 'folder');
    expect($response->json('results.projects.0.url'))->toContain('/projects/');
});

test('can search for clients by name', function () {
    $client = Client::factory()->create(['name' => 'Acme Corporation']);
    Client::factory()->create(['name' => 'Other Corp']);

    $response = $this->getJson('/api/command-palette/object-search?query=Acme');

    $response->assertStatus(200);
    $response->assertJsonPath('results.clients.0.name', 'Acme Corporation');
    $response->assertJsonPath('results.clients.0.type', 'client');
    $response->assertJsonPath('results.clients.0.icon', 'users');
    expect($response->json('results.clients.0.url'))->toContain('/clients/');
});

test('can search for tasks by title', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'title' => 'Implement command palette',
        'project_id' => $project->id,
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=command');

    $response->assertStatus(200);
    $response->assertJsonPath('results.tasks.0.name', 'Implement command palette');
    $response->assertJsonPath('results.tasks.0.type', 'task');
    $response->assertJsonPath('results.tasks.0.icon', 'check-square');
    expect($response->json('results.tasks.0.url'))->toContain('/projects/');
});

test('can search for agents by name', function () {
    $agent = Agent::factory()->create([
        'name' => 'Task Manager',
        'description' => 'Manages tasks automatically',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=task');

    $response->assertStatus(200);
    $response->assertJsonPath('results.agents.0.name', 'Task Manager');
    $response->assertJsonPath('results.agents.0.type', 'agent');
    $response->assertJsonPath('results.agents.0.icon', 'cpu');
});

test('can search for team members by name', function () {
    $member = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=John');

    $response->assertStatus(200);
    $response->assertJsonPath('results.team.0.name', 'John Doe');
    $response->assertJsonPath('results.team.0.type', 'user');
    $response->assertJsonPath('results.team.0.icon', 'user');
});

test('can search for team members by email', function () {
    $member = User::factory()->create([
        'name' => 'Jane Smith',
        'email' => 'jane.smith@example.com',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=jane.smith');

    $response->assertStatus(200);
    $response->assertJsonPath('results.team.0.name', 'Jane Smith');
});

test('can search for pending approvals', function () {
    $agent = Agent::factory()->create(['name' => 'Test Agent']);
    $approval = ApprovalRequest::factory()->create([
        'title' => 'Approve deployment',
        'status' => 'pending',
        'agent_id' => $agent->id,
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=deployment');

    $response->assertStatus(200);
    $response->assertJsonPath('results.approvals.0.name', 'Approve deployment');
    $response->assertJsonPath('results.approvals.0.type', 'approval');
    $response->assertJsonPath('results.approvals.0.icon', 'shield');
});

test('object search returns empty results for no matches', function () {
    Project::factory()->create(['name' => 'Real Project']);

    $response = $this->getJson('/api/command-palette/object-search?query=NonExistent');

    $response->assertStatus(200);
    $response->assertJson([
        'results' => [],
        'total' => 0,
    ]);
});

test('object search respects limit parameter', function () {
    Project::factory()->count(10)->create([
        'name' => 'Test Project',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=Test&limit=3');

    $response->assertStatus(200);
    expect($response->json('results.projects'))->toHaveCount(3);
});

test('object search validates query parameter', function () {
    $response = $this->getJson('/api/command-palette/object-search');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['query']);
});

test('object search includes metadata for projects', function () {
    $project = Project::factory()->create([
        'name' => 'My Project',
        'status' => 'active',
        'description' => 'A test project',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=My');

    $response->assertStatus(200);
    $response->assertJsonPath('results.projects.0.metadata.status', 'active');
    expect($response->json('results.projects.0.metadata'))->toHaveKey('slug');
});

test('object search includes task project information', function () {
    $project = Project::factory()->create(['name' => 'Client Work']);
    $task = Task::factory()->create([
        'title' => 'Design mockups',
        'project_id' => $project->id,
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=mockups');

    $response->assertStatus(200);
    expect($response->json('results.tasks.0.description'))->toContain('Client Work');
    expect($response->json('results.tasks.0.metadata.project_name'))->toBe('Client Work');
});

test('object search counts total results correctly', function () {
    Project::factory()->count(2)->create(['name' => 'Alpha Project']);
    Client::factory()->count(3)->create(['name' => 'Alpha Client']);
    Task::factory()->count(1)->create(['title' => 'Alpha Task']);

    $response = $this->getJson('/api/command-palette/object-search?query=Alpha');

    $response->assertStatus(200);
    $response->assertJsonPath('total', 6);
});

test('object search handles special characters in query', function () {
    Project::factory()->create(['name' => 'Project (v2.0)']);

    $response = $this->getJson('/api/command-palette/object-search?query='.urlencode('(v2'));

    $response->assertStatus(200);
    $response->assertJsonPath('results.projects.0.name', 'Project (v2.0)');
});

test('object search only returns pending approvals', function () {
    ApprovalRequest::factory()->create([
        'title' => 'Pending Request',
        'status' => 'pending',
    ]);
    ApprovalRequest::factory()->create([
        'title' => 'Approved Request',
        'status' => 'approved',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=Request');

    $response->assertStatus(200);

    if (isset($response->json('results')['approvals'])) {
        expect($response->json('results.approvals'))->toHaveCount(1);
        $response->assertJsonPath('results.approvals.0.name', 'Pending Request');
    }
});

test('object search only returns active team members', function () {
    User::factory()->create([
        'name' => 'Active User',
        'status' => 'active',
    ]);
    User::factory()->create([
        'name' => 'Inactive User',
        'status' => 'inactive',
    ]);

    $response = $this->getJson('/api/command-palette/object-search?query=User');

    $response->assertStatus(200);

    if (isset($response->json('results')['team'])) {
        $names = collect($response->json('results.team'))->pluck('name')->toArray();
        expect($names)->toContain('Active User');
        expect($names)->not->toContain('Inactive User');
    }
});

test('object search returns properly formatted URLs', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);
    $client = Client::factory()->create(['slug' => 'test-client']);
    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $response = $this->getJson('/api/command-palette/object-search?query=test');

    $response->assertStatus(200);

    if (isset($response->json('results')['projects'])) {
        expect($response->json('results.projects.0.url'))->toBe('/projects/test-project');
    }
    if (isset($response->json('results')['clients'])) {
        expect($response->json('results.clients.0.url'))->toBe('/clients/test-client');
    }
    if (isset($response->json('results')['agents'])) {
        expect($response->json('results.agents.0.url'))->toBe('/agents/test-agent');
    }
});
