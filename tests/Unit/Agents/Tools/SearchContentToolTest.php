<?php

use App\Agents\Tools\SearchContentTool;
use App\Models\ContentSuggestion;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new SearchContentTool;
    expect($tool->name())->toBe('Search Content');
});

test('getDescription returns correct description', function () {
    $tool = new SearchContentTool;
    expect($tool->description())->toContain('Search for content');
});

test('getParameters includes all filter options', function () {
    $tool = new SearchContentTool;
    $schema = $tool->inputSchema();

    expect($schema['properties'])->toHaveKeys([
        'query', 'status', 'type', 'site_id',
        'scheduled_after', 'scheduled_before', 'limit',
    ]);
});

test('execute returns content suggestions', function () {
    ContentSuggestion::factory()->count(3)->create();

    $tool = new SearchContentTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['count', 'content', 'available_sites'])
        ->and($result['count'])->toBe(3);
});

test('execute searches by title', function () {
    ContentSuggestion::factory()->create(['title' => 'How to Build Apps']);
    ContentSuggestion::factory()->create(['title' => 'Marketing Tips']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['query' => 'Build']);

    expect($result['count'])->toBe(1)
        ->and($result['content'][0]['title'])->toBe('How to Build Apps');
});

test('execute searches by content', function () {
    ContentSuggestion::factory()->create(['content' => 'This is about Laravel']);
    ContentSuggestion::factory()->create(['content' => 'This is about React']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['query' => 'Laravel']);

    expect($result['count'])->toBe(1);
});

test('execute filters by status', function () {
    ContentSuggestion::factory()->create(['status' => 'pending']);
    ContentSuggestion::factory()->create(['status' => 'approved']);
    ContentSuggestion::factory()->create(['status' => 'published']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['status' => 'approved']);

    expect($result['count'])->toBe(1)
        ->and($result['content'][0]['status'])->toBe('approved');
});

test('execute filters by content type', function () {
    ContentSuggestion::factory()->create(['content_type' => 'blog_post']);
    ContentSuggestion::factory()->create(['content_type' => 'case_study']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['type' => 'case_study']);

    expect($result['count'])->toBe(1)
        ->and($result['content'][0]['content_type'])->toBe('case_study');
});

test('execute filters by WordPress site', function () {
    $site = WordPressSite::factory()->create();

    ContentSuggestion::factory()->create(['wordpress_site_id' => $site->id]);
    ContentSuggestion::factory()->create(['wordpress_site_id' => null]);

    $tool = new SearchContentTool;
    $result = $tool->execute(['site_id' => $site->id]);

    expect($result['count'])->toBe(1)
        ->and($result['content'][0]['site_id'])->toBe($site->id);
});

test('execute filters by scheduled after date', function () {
    ContentSuggestion::factory()->create(['scheduled_at' => '2025-01-01']);
    ContentSuggestion::factory()->create(['scheduled_at' => '2025-06-01']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['scheduled_after' => '2025-05-01']);

    expect($result['count'])->toBe(1);
});

test('execute filters by scheduled before date', function () {
    ContentSuggestion::factory()->create(['scheduled_at' => '2025-01-01']);
    ContentSuggestion::factory()->create(['scheduled_at' => '2025-06-01']);

    $tool = new SearchContentTool;
    $result = $tool->execute(['scheduled_before' => '2025-05-01']);

    expect($result['count'])->toBe(1);
});

test('execute respects limit parameter', function () {
    ContentSuggestion::factory()->count(20)->create();

    $tool = new SearchContentTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute defaults to limit of 10', function () {
    ContentSuggestion::factory()->count(20)->create();

    $tool = new SearchContentTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('execute includes available sites in response', function () {
    WordPressSite::factory()->count(3)->create();

    $tool = new SearchContentTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKey('available_sites')
        ->and($result['available_sites'])->toHaveCount(3);
});

test('execute includes site name in content', function () {
    $site = WordPressSite::factory()->create(['name' => 'My Blog']);
    ContentSuggestion::factory()->create(['wordpress_site_id' => $site->id]);

    $tool = new SearchContentTool;
    $result = $tool->execute([]);

    expect($result['content'][0]['site_name'])->toBe('My Blog');
});

test('validate rejects invalid status', function () {
    $tool = new SearchContentTool;
    $tool->validate(['status' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid type', function () {
    $tool = new SearchContentTool;
    $tool->validate(['type' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid site_id', function () {
    $tool = new SearchContentTool;
    $tool->validate(['site_id' => 99999]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid date format', function () {
    $tool = new SearchContentTool;
    $tool->validate(['scheduled_after' => 'not-a-date']);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $site = WordPressSite::factory()->create();

    $tool = new SearchContentTool;
    $validated = $tool->validate([
        'query' => 'test',
        'status' => 'approved',
        'type' => 'blog_post',
        'site_id' => $site->id,
        'limit' => 20,
    ]);

    expect($validated)->toHaveKeys(['query', 'status', 'type', 'site_id', 'limit']);
});
