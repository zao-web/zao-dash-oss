<?php

use App\Agents\Tools\CreateProjectTool;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new CreateProjectTool;
    expect($tool->name())->toBe('Create Project');
});

test('getDescription returns correct description', function () {
    $tool = new CreateProjectTool;
    expect($tool->description())->toContain('Create a new project');
});

test('requiresApproval returns true', function () {
    $tool = new CreateProjectTool;
    expect($tool->requiresApproval())->toBeTrue();
});

test('riskLevel returns medium', function () {
    $tool = new CreateProjectTool;
    expect($tool->riskLevel())->toBe('medium');
});

test('getParameters requires name', function () {
    $tool = new CreateProjectTool;
    $schema = $tool->inputSchema();

    expect($schema['required'])->toContain('name');
});

test('execute creates project with minimal params', function () {
    $tool = new CreateProjectTool;
    $result = $tool->execute(['name' => 'New Project']);

    expect($result['created'])->toBeTrue()
        ->and($result['project']['name'])->toBe('New Project');

    $this->assertDatabaseHas('projects', ['name' => 'New Project']);
});

test('execute creates project with all params', function () {
    $client = Client::factory()->create();

    $tool = new CreateProjectTool;
    $result = $tool->execute([
        'name' => 'Website Redesign',
        'description' => 'Complete website overhaul',
        'client_id' => $client->id,
        'status' => 'active',
        'budget' => 50000,
    ]);

    expect($result['created'])->toBeTrue();

    $this->assertDatabaseHas('projects', [
        'name' => 'Website Redesign',
        'description' => 'Complete website overhaul',
        'client_id' => $client->id,
        'status' => 'active',
        'budget' => 50000,
    ]);
});

test('execute resolves client by name', function () {
    $client = Client::factory()->create(['name' => 'ACME Corp']);

    $tool = new CreateProjectTool;
    $result = $tool->execute([
        'name' => 'Project',
        'client_name' => 'ACME',
    ]);

    expect($result['project']['client'])->toBe('ACME Corp');
    $this->assertDatabaseHas('projects', ['client_id' => $client->id]);
});

test('execute generates slug from name', function () {
    $tool = new CreateProjectTool;
    $result = $tool->execute(['name' => 'My Cool Project']);

    expect($result['project']['slug'])->toBe('my-cool-project');
});

test('execute defaults status to planning', function () {
    $tool = new CreateProjectTool;
    $tool->execute(['name' => 'Project']);

    $this->assertDatabaseHas('projects', [
        'name' => 'Project',
        'status' => 'planning',
    ]);
});

test('validate rejects missing name', function () {
    $tool = new CreateProjectTool;
    $tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid status', function () {
    $tool = new CreateProjectTool;
    $tool->validate([
        'name' => 'Project',
        'status' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects negative budget', function () {
    $tool = new CreateProjectTool;
    $tool->validate([
        'name' => 'Project',
        'budget' => -1000,
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $client = Client::factory()->create();

    $tool = new CreateProjectTool;
    $validated = $tool->validate([
        'name' => 'Project',
        'description' => 'Description',
        'client_id' => $client->id,
        'status' => 'active',
        'budget' => 10000,
    ]);

    expect($validated)->toHaveKeys(['name', 'description', 'client_id', 'status', 'budget']);
});
