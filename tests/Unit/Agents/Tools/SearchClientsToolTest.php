<?php

use App\Agents\Tools\SearchClientsTool;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new SearchClientsTool;
    expect($tool->name())->toBe('Search Clients');
});

test('getDescription returns correct description', function () {
    $tool = new SearchClientsTool;
    expect($tool->description())->toContain('Search for clients');
});

test('execute returns all clients by default', function () {
    Client::factory()->count(3)->create();

    $tool = new SearchClientsTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['count', 'clients'])
        ->and($result['count'])->toBe(3);
});

test('execute searches by name', function () {
    Client::factory()->create(['name' => 'ACME Corp']);
    Client::factory()->create(['name' => 'TechCo Inc']);

    $tool = new SearchClientsTool;
    $result = $tool->execute(['query' => 'ACME']);

    expect($result['count'])->toBe(1)
        ->and($result['clients'][0]['name'])->toBe('ACME Corp');
});

test('execute searches by email', function () {
    Client::factory()->create(['email' => 'contact@acme.com']);
    Client::factory()->create(['email' => 'info@techco.com']);

    $tool = new SearchClientsTool;
    $result = $tool->execute(['query' => 'acme']);

    expect($result['count'])->toBe(1);
});

test('execute filters by status', function () {
    Client::factory()->create(['status' => 'active']);
    Client::factory()->create(['status' => 'inactive']);
    Client::factory()->create(['status' => 'prospect']);

    $tool = new SearchClientsTool;
    $result = $tool->execute(['status' => 'active']);

    expect($result['count'])->toBe(1)
        ->and($result['clients'][0]['status'])->toBe('active');
});

test('execute includes projects count', function () {
    $client = Client::factory()->create();
    Project::factory()->count(3)->create(['client_id' => $client->id]);

    $tool = new SearchClientsTool;
    $result = $tool->execute([]);

    expect($result['clients'][0])->toHaveKey('projects_count')
        ->and($result['clients'][0]['projects_count'])->toBe(3);
});

test('execute respects limit parameter', function () {
    Client::factory()->count(20)->create();

    $tool = new SearchClientsTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute defaults to limit of 10', function () {
    Client::factory()->count(20)->create();

    $tool = new SearchClientsTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('execute sorts by name', function () {
    Client::factory()->create(['name' => 'Zebra Corp']);
    Client::factory()->create(['name' => 'Alpha Inc']);

    $tool = new SearchClientsTool;
    $result = $tool->execute([]);

    expect($result['clients'][0]['name'])->toBe('Alpha Inc');
});

test('validate rejects invalid status', function () {
    $tool = new SearchClientsTool;
    $tool->validate(['status' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new SearchClientsTool;
    $validated = $tool->validate([
        'query' => 'test',
        'status' => 'active',
        'limit' => 20,
    ]);

    expect($validated)->toHaveKeys(['query', 'status', 'limit']);
});
