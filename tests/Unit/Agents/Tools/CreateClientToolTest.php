<?php

use App\Agents\Tools\CreateClientTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new CreateClientTool;
    expect($tool->name())->toBe('Create Client');
});

test('getDescription returns correct description', function () {
    $tool = new CreateClientTool;
    expect($tool->description())->toContain('Create a new client');
});

test('requiresApproval returns true', function () {
    $tool = new CreateClientTool;
    expect($tool->requiresApproval())->toBeTrue();
});

test('riskLevel returns medium', function () {
    $tool = new CreateClientTool;
    expect($tool->riskLevel())->toBe('medium');
});

test('getParameters requires name', function () {
    $tool = new CreateClientTool;
    $schema = $tool->inputSchema();

    expect($schema['required'])->toContain('name');
});

test('execute creates client with minimal params', function () {
    $tool = new CreateClientTool;
    $result = $tool->execute(['name' => 'ACME Corp']);

    expect($result['created'])->toBeTrue()
        ->and($result['client']['name'])->toBe('ACME Corp');

    $this->assertDatabaseHas('clients', ['name' => 'ACME Corp']);
});

test('execute creates client with all params', function () {
    $tool = new CreateClientTool;
    $result = $tool->execute([
        'name' => 'TechCo Inc',
        'email' => 'contact@techco.com',
        'phone' => '555-1234',
        'website' => 'https://techco.com',
        'description' => 'Technology company',
    ]);

    expect($result['created'])->toBeTrue();

    $this->assertDatabaseHas('clients', [
        'name' => 'TechCo Inc',
        'email' => 'contact@techco.com',
        'phone' => '555-1234',
        'website' => 'https://techco.com',
        'description' => 'Technology company',
    ]);
});

test('execute generates slug from name', function () {
    $tool = new CreateClientTool;
    $result = $tool->execute(['name' => 'ACME Corporation']);

    expect($result['client']['slug'])->toBe('acme-corporation');
});

test('execute sets status to active', function () {
    $tool = new CreateClientTool;
    $tool->execute(['name' => 'Client']);

    $this->assertDatabaseHas('clients', [
        'name' => 'Client',
        'status' => 'active',
    ]);
});

test('validate rejects missing name', function () {
    $tool = new CreateClientTool;
    $tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid email', function () {
    $tool = new CreateClientTool;
    $tool->validate([
        'name' => 'Client',
        'email' => 'not-an-email',
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid website', function () {
    $tool = new CreateClientTool;
    $tool->validate([
        'name' => 'Client',
        'website' => 'not-a-url',
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new CreateClientTool;
    $validated = $tool->validate([
        'name' => 'Client',
        'email' => 'test@example.com',
        'phone' => '555-1234',
        'website' => 'https://example.com',
        'description' => 'Description',
    ]);

    expect($validated)->toHaveKeys(['name', 'email', 'phone', 'website', 'description']);
});
