<?php

use App\Agents\Tools\WebSearchTool;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tool = new WebSearchTool;
});

test('getName returns correct name', function () {
    expect($this->tool->name())->toBe('Web Search');
});

test('getDescription returns correct description', function () {
    expect($this->tool->description())->toContain('Search the web');
});

test('getParameters requires query', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['required'])->toContain('query')
        ->and($schema['properties'])->toHaveKey('query');
});

test('getParameters includes type and limit', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['properties'])->toHaveKeys(['query', 'type', 'limit'])
        ->and($schema['properties']['type']['enum'])->toContain('general', 'news', 'company');
});

test('validate rejects missing query', function () {
    $this->tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate rejects invalid type', function () {
    $this->tool->validate([
        'query' => 'test',
        'type' => 'invalid',
    ]);
})->throws(InvalidArgumentException::class);

test('validate rejects limit over max', function () {
    $this->tool->validate([
        'query' => 'test',
        'limit' => 20,
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $validated = $this->tool->validate([
        'query' => 'test search',
        'type' => 'news',
        'limit' => 5,
    ]);

    expect($validated)->toHaveKeys(['query', 'type', 'limit']);
});

test('execute returns not configured when no API key', function () {
    Config::set('services.serper.api_key', null);
    Config::set('services.serpapi.api_key', null);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('not_configured')
        ->and($result)->toHaveKey('message')
        ->and($result['results'])->toBeArray()->toBeEmpty();
});

test('execute calls Serper API when configured', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                [
                    'title' => 'Test Result',
                    'link' => 'https://example.com',
                    'snippet' => 'Test snippet',
                ],
            ],
        ], 200),
    ]);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('success')
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['title'])->toBe('Test Result');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-API-KEY', 'test-key')
            && $request->url() === 'https://google.serper.dev/search';
    });
});

test('execute uses news endpoint for news type', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'News Result', 'link' => 'https://news.com', 'snippet' => 'News'],
            ],
        ], 200),
    ]);

    $this->tool->execute(['query' => 'test', 'type' => 'news']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/news');
    });
});

test('execute respects limit parameter', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => array_fill(0, 10, [
                'title' => 'Result',
                'link' => 'https://example.com',
                'snippet' => 'Snippet',
            ]),
        ], 200),
    ]);

    $result = $this->tool->execute(['query' => 'test', 'limit' => 3]);

    expect($result['results'])->toHaveCount(3);
});

test('execute defaults to limit of 5', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => array_fill(0, 10, [
                'title' => 'Result',
                'link' => 'https://example.com',
                'snippet' => 'Snippet',
            ]),
        ], 200),
    ]);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result['results'])->toHaveCount(5);
});

test('execute handles API errors gracefully', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([], 500),
    ]);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('error')
        ->and($result['results'])->toBeEmpty();
});

test('execute handles exceptions gracefully', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => function () {
            throw new Exception('Network error');
        },
    ]);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('error')
        ->and($result)->toHaveKey('message')
        ->and($result['message'])->toContain('Network error');
});

test('execute includes knowledge graph when available', function () {
    Config::set('services.serper.api_key', 'test-key');

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'organic' => [
                ['title' => 'Result', 'link' => 'https://example.com', 'snippet' => 'Test'],
            ],
            'knowledgeGraph' => [
                'title' => 'Entity',
                'description' => 'Entity description',
            ],
        ], 200),
    ]);

    $result = $this->tool->execute(['query' => 'test']);

    expect($result)->toHaveKey('knowledge_graph')
        ->and($result['knowledge_graph'])->not->toBeNull();
});
