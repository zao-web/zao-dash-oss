<?php

use App\Agents\Tools\SearchLeadsTool;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new SearchLeadsTool;
    expect($tool->name())->toBe('Search Leads');
});

test('getDescription returns correct description', function () {
    $tool = new SearchLeadsTool;
    expect($tool->description())->toContain('Search for leads');
});

test('getParameters includes search options', function () {
    $tool = new SearchLeadsTool;
    $schema = $tool->inputSchema();

    expect($schema['properties'])->toHaveKeys(['query', 'stage', 'min_deal_value', 'limit']);
});

test('execute returns all leads by default', function () {
    Lead::factory()->count(5)->create();

    $tool = new SearchLeadsTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['count', 'leads'])
        ->and($result['count'])->toBe(5);
});

test('execute searches by company name', function () {
    Lead::factory()->create(['company_name' => 'ACME Corp']);
    Lead::factory()->create(['company_name' => 'TechCo']);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['query' => 'ACME']);

    expect($result['count'])->toBe(1)
        ->and($result['leads'][0]['company_name'])->toBe('ACME Corp');
});

test('execute searches by contact name', function () {
    Lead::factory()->create(['contact_name' => 'John Doe']);
    Lead::factory()->create(['contact_name' => 'Jane Smith']);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['query' => 'John']);

    expect($result['count'])->toBe(1)
        ->and($result['leads'][0]['contact_name'])->toBe('John Doe');
});

test('execute filters by stage', function () {
    Lead::factory()->create(['stage' => 'new']);
    Lead::factory()->create(['stage' => 'qualified']);
    Lead::factory()->create(['stage' => 'won']);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['stage' => 'qualified']);

    expect($result['count'])->toBe(1)
        ->and($result['leads'][0]['stage'])->toBe('qualified');
});

test('execute filters by multiple stages', function () {
    Lead::factory()->create(['stage' => 'new']);
    Lead::factory()->create(['stage' => 'qualified']);
    Lead::factory()->create(['stage' => 'won']);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['stages' => ['new', 'qualified']]);

    expect($result['count'])->toBe(2);
});

test('execute filters by minimum deal value', function () {
    Lead::factory()->create(['deal_value' => 1000]);
    Lead::factory()->create(['deal_value' => 5000]);
    Lead::factory()->create(['deal_value' => 10000]);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['min_deal_value' => 5000]);

    expect($result['count'])->toBe(2);
});

test('execute filters by days since contact', function () {
    Lead::factory()->create(['last_contacted_at' => now()->subDays(10)]);
    Lead::factory()->create(['last_contacted_at' => now()->subDays(5)]);
    Lead::factory()->create(['last_contacted_at' => null]);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['days_since_contact' => 7]);

    expect($result['count'])->toBe(2); // One old contact + one null
});

test('execute sorts by deal value', function () {
    Lead::factory()->create(['deal_value' => 1000]);
    Lead::factory()->create(['deal_value' => 10000]);
    Lead::factory()->create(['deal_value' => 5000]);

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['sort_by' => 'deal_value']);

    expect($result['leads'][0]['deal_value'])->toBe(10000);
});

test('execute respects limit parameter', function () {
    Lead::factory()->count(20)->create();

    $tool = new SearchLeadsTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute defaults to limit of 10', function () {
    Lead::factory()->count(20)->create();

    $tool = new SearchLeadsTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('execute includes days since contact in response', function () {
    Lead::factory()->create(['last_contacted_at' => now()->subDays(5)]);

    $tool = new SearchLeadsTool;
    $result = $tool->execute([]);

    expect($result['leads'][0])->toHaveKey('days_since_contact')
        ->and($result['leads'][0]['days_since_contact'])->toBeGreaterThanOrEqual(5);
});

test('validate rejects invalid stage', function () {
    $tool = new SearchLeadsTool;
    $tool->validate(['stage' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid sort_by', function () {
    $tool = new SearchLeadsTool;
    $tool->validate(['sort_by' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects negative deal value', function () {
    $tool = new SearchLeadsTool;
    $tool->validate(['min_deal_value' => -100]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new SearchLeadsTool;
    $validated = $tool->validate([
        'query' => 'test',
        'stage' => 'qualified',
        'min_deal_value' => 1000,
        'limit' => 10,
    ]);

    expect($validated)->toHaveKeys(['query', 'stage', 'min_deal_value', 'limit']);
});
