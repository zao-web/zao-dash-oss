<?php

use App\Agents\Tools\NavigateTool;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new NavigateTool;
    expect($tool->name())->toBe('Navigate');
});

test('getDescription returns correct description', function () {
    $tool = new NavigateTool;
    expect($tool->description())->toContain('navigation');
});

test('getParameters includes destination options', function () {
    $tool = new NavigateTool;
    $schema = $tool->inputSchema();

    expect($schema['properties']['destination']['enum'])
        ->toContain('dashboard', 'projects', 'tasks', 'clients', 'pipeline', 'agents');
});

test('execute returns dashboard route', function () {
    $tool = new NavigateTool;
    $result = $tool->execute(['destination' => 'dashboard']);

    expect($result)->toHaveKeys(['type', 'url', 'label'])
        ->and($result['type'])->toBe('navigation')
        ->and($result['url'])->toBe('/')
        ->and($result['label'])->toBe('Dashboard');
});

test('execute returns projects route', function () {
    $tool = new NavigateTool;
    $result = $tool->execute(['destination' => 'projects']);

    expect($result['url'])->toBe('/projects');
});

test('execute returns clients route', function () {
    $tool = new NavigateTool;
    $result = $tool->execute(['destination' => 'clients']);

    expect($result['url'])->toBe('/clients');
});

test('execute returns agents route', function () {
    $tool = new NavigateTool;
    $result = $tool->execute(['destination' => 'agents']);

    expect($result['url'])->toBe('/agents');
});

test('execute navigates to project by ID', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'project',
        'entity_id' => $project->id,
    ]);

    expect($result)->toHaveKey('url')
        ->and($result['url'])->toBe("/projects/{$project->slug}")
        ->and($result['entity']['name'])->toBe($project->name);
});

test('execute navigates to project by name', function () {
    $project = Project::factory()->create([
        'name' => 'Test Project',
        'slug' => 'test-project',
    ]);

    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'project',
        'entity_name' => 'Test',
    ]);

    expect($result['url'])->toBe("/projects/{$project->slug}");
});

test('execute navigates to client by ID', function () {
    $client = Client::factory()->create(['slug' => 'test-client']);

    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'client',
        'entity_id' => $client->id,
    ]);

    expect($result['url'])->toBe("/clients/{$client->slug}")
        ->and($result['entity']['name'])->toBe($client->name);
});

test('execute navigates to client by name', function () {
    $client = Client::factory()->create([
        'name' => 'ACME Corp',
        'slug' => 'acme-corp',
    ]);

    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'client',
        'entity_name' => 'ACME',
    ]);

    expect($result['url'])->toBe("/clients/{$client->slug}");
});

test('execute returns not found for missing entity', function () {
    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'project',
        'entity_id' => 99999,
    ]);

    expect($result['type'])->toBe('not_found')
        ->and($result)->toHaveKey('message');
});

test('execute returns error when no params provided', function () {
    $tool = new NavigateTool;
    $result = $tool->execute([]);

    expect($result['type'])->toBe('error')
        ->and($result)->toHaveKey('message');
});

test('validate rejects invalid destination', function () {
    $tool = new NavigateTool;

    $tool->validate(['destination' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid entity_type', function () {
    $tool = new NavigateTool;

    $tool->validate(['entity_type' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new NavigateTool;

    $validated = $tool->validate([
        'destination' => 'dashboard',
    ]);

    expect($validated)->toHaveKey('destination');
});

test('validate accepts entity navigation params', function () {
    $tool = new NavigateTool;

    $validated = $tool->validate([
        'entity_type' => 'project',
        'entity_id' => 1,
    ]);

    expect($validated)->toHaveKeys(['entity_type', 'entity_id']);
});

test('execute includes entity metadata in response', function () {
    $project = Project::factory()->create();

    $tool = new NavigateTool;
    $result = $tool->execute([
        'entity_type' => 'project',
        'entity_id' => $project->id,
    ]);

    expect($result)->toHaveKey('entity')
        ->and($result['entity'])->toHaveKeys(['type', 'id', 'name'])
        ->and($result['entity']['type'])->toBe('project')
        ->and($result['entity']['id'])->toBe($project->id);
});
