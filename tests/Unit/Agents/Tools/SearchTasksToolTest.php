<?php

use App\Agents\Tools\SearchTasksTool;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new SearchTasksTool;
    expect($tool->name())->toBe('Search Tasks');
});

test('getDescription returns correct description', function () {
    $tool = new SearchTasksTool;
    expect($tool->description())->toContain('Search for tasks');
});

test('getParameters returns valid schema', function () {
    $tool = new SearchTasksTool;
    $schema = $tool->inputSchema();

    expect($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties')
        ->and($schema['properties'])->toHaveKeys(['query', 'status', 'priority', 'limit']);
});

test('execute returns tasks matching query', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    Task::factory()->create([
        'title' => 'Build feature X',
        'description' => 'Important feature',
        'project_id' => $project->id,
        'assignee_id' => $user->id,
    ]);

    Task::factory()->create([
        'title' => 'Fix bug Y',
        'description' => 'Critical bug',
    ]);

    $tool = new SearchTasksTool;
    $result = $tool->execute(['query' => 'feature']);

    expect($result)->toHaveKey('count')
        ->and($result['count'])->toBe(1)
        ->and($result)->toHaveKey('tasks')
        ->and($result['tasks'][0]['title'])->toBe('Build feature X');
});

test('execute filters by status', function () {
    Task::factory()->create(['status' => 'pending']);
    Task::factory()->create(['status' => 'completed']);
    Task::factory()->create(['status' => 'in_progress']);

    $tool = new SearchTasksTool;
    $result = $tool->execute(['status' => 'completed']);

    expect($result['count'])->toBe(1)
        ->and($result['tasks'][0]['status'])->toBe('completed');
});

test('execute filters by priority', function () {
    Task::factory()->create(['priority' => 'low']);
    Task::factory()->create(['priority' => 'high']);
    Task::factory()->create(['priority' => 'urgent']);

    $tool = new SearchTasksTool;
    $result = $tool->execute(['priority' => 'urgent']);

    expect($result['count'])->toBe(1)
        ->and($result['tasks'][0]['priority'])->toBe('urgent');
});

test('execute respects limit parameter', function () {
    Task::factory()->count(20)->create();

    $tool = new SearchTasksTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute uses default limit of 10', function () {
    Task::factory()->count(20)->create();

    $tool = new SearchTasksTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('validate rejects invalid status', function () {
    $tool = new SearchTasksTool;

    $tool->validate(['status' => 'invalid_status']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid priority', function () {
    $tool = new SearchTasksTool;

    $tool->validate(['priority' => 'invalid_priority']);
})->throws(InvalidArgumentException::class);

test('validate rejects limit over maximum', function () {
    $tool = new SearchTasksTool;

    $tool->validate(['limit' => 100]);
})->throws(InvalidArgumentException::class);

test('validate rejects negative limit', function () {
    $tool = new SearchTasksTool;

    $tool->validate(['limit' => -1]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new SearchTasksTool;

    $validated = $tool->validate([
        'query' => 'test',
        'status' => 'pending',
        'priority' => 'high',
        'limit' => 20,
    ]);

    expect($validated)->toHaveKeys(['query', 'status', 'priority', 'limit']);
});

test('execute returns related project and assignee data', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $user = User::factory()->create(['name' => 'John Doe']);

    Task::factory()->create([
        'project_id' => $project->id,
        'assignee_id' => $user->id,
    ]);

    $tool = new SearchTasksTool;
    $result = $tool->execute([]);

    expect($result['tasks'][0])->toHaveKey('project')
        ->and($result['tasks'][0]['project'])->toBe('Test Project')
        ->and($result['tasks'][0])->toHaveKey('assignee')
        ->and($result['tasks'][0]['assignee'])->toBe('John Doe');
});
