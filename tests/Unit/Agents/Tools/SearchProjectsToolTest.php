<?php

use App\Agents\Tools\SearchProjectsTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new SearchProjectsTool;
    expect($tool->name())->toBe('Search Projects');
});

test('getDescription returns correct description', function () {
    $tool = new SearchProjectsTool;
    expect($tool->description())->toContain('Search for projects');
});

test('execute returns all projects by default', function () {
    Project::factory()->count(3)->create();

    $tool = new SearchProjectsTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['count', 'projects'])
        ->and($result['count'])->toBe(3);
});

test('execute searches by name', function () {
    Project::factory()->create(['name' => 'Website Redesign']);
    Project::factory()->create(['name' => 'Mobile App']);

    $tool = new SearchProjectsTool;
    $result = $tool->execute(['query' => 'Website']);

    expect($result['count'])->toBe(1)
        ->and($result['projects'][0]['name'])->toBe('Website Redesign');
});

test('execute filters by status', function () {
    Project::factory()->create(['status' => 'active']);
    Project::factory()->create(['status' => 'completed']);
    Project::factory()->create(['status' => 'on_hold']);

    $tool = new SearchProjectsTool;
    $result = $tool->execute(['status' => 'active']);

    expect($result['count'])->toBe(1)
        ->and($result['projects'][0]['status'])->toBe('active');
});

test('execute filters by client_id', function () {
    $client1 = Client::factory()->create();
    $client2 = Client::factory()->create();

    Project::factory()->create(['client_id' => $client1->id]);
    Project::factory()->create(['client_id' => $client2->id]);

    $tool = new SearchProjectsTool;
    $result = $tool->execute(['client_id' => $client1->id]);

    expect($result['count'])->toBe(1);
});

test('execute includes task counts', function () {
    $project = Project::factory()->create();
    Task::factory()->count(5)->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'status' => 'completed',
    ]);

    $tool = new SearchProjectsTool;
    $result = $tool->execute([]);

    expect($result['projects'][0])->toHaveKeys(['tasks_count', 'completed_tasks_count'])
        ->and($result['projects'][0]['tasks_count'])->toBe(8)
        ->and($result['projects'][0]['completed_tasks_count'])->toBe(3);
});

test('execute includes client name', function () {
    $client = Client::factory()->create(['name' => 'ACME Corp']);
    Project::factory()->create(['client_id' => $client->id]);

    $tool = new SearchProjectsTool;
    $result = $tool->execute([]);

    expect($result['projects'][0]['client'])->toBe('ACME Corp');
});

test('execute respects limit parameter', function () {
    Project::factory()->count(20)->create();

    $tool = new SearchProjectsTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute defaults to limit of 10', function () {
    Project::factory()->count(20)->create();

    $tool = new SearchProjectsTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('validate rejects invalid status', function () {
    $tool = new SearchProjectsTool;
    $tool->validate(['status' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid client_id', function () {
    $tool = new SearchProjectsTool;
    $tool->validate(['client_id' => 99999]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $client = Client::factory()->create();

    $tool = new SearchProjectsTool;
    $validated = $tool->validate([
        'query' => 'test',
        'status' => 'active',
        'client_id' => $client->id,
        'limit' => 20,
    ]);

    expect($validated)->toHaveKeys(['query', 'status', 'client_id', 'limit']);
});
