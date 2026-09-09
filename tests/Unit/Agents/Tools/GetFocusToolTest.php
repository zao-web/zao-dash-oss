<?php

use App\Agents\Tools\GetFocusTool;
use App\Services\CapabilitySynthesisService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockService = Mockery::mock(CapabilitySynthesisService::class);
    $this->tool = new GetFocusTool($this->mockService);
});

test('getName returns correct name', function () {
    expect($this->tool->name())->toBe('Get Focus');
});

test('getDescription returns correct description', function () {
    expect($this->tool->description())->toContain('Get recommendations');
});

test('getParameters includes type options', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['properties']['type']['enum'])
        ->toContain('briefing')
        ->toContain('items')
        ->toContain('capabilities')
        ->toContain('gaps');
});

test('execute returns briefing when type is briefing', function () {
    $this->mockService->shouldReceive('getMorningBriefing')
        ->once()
        ->andReturn([
            'greeting' => 'Good morning',
            'summary' => 'Test summary',
            'recommendations' => ['rec1', 'rec2'],
            'top_priorities' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'],
        ]);

    $result = $this->tool->execute(['type' => 'briefing']);

    expect($result)->toHaveKeys(['greeting', 'summary', 'recommendations', 'top_priorities'])
        ->and($result['greeting'])->toBe('Good morning')
        ->and($result['top_priorities'])->toHaveCount(5);
});

test('execute returns items when type is items', function () {
    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn([
            ['title' => 'Item 1', 'priority' => 'high'],
            ['title' => 'Item 2', 'priority' => 'critical'],
        ]);

    $result = $this->tool->execute(['type' => 'items']);

    expect($result)->toHaveKeys(['total', 'items'])
        ->and($result['total'])->toBe(2)
        ->and($result['items'])->toHaveCount(2);
});

test('execute returns capabilities when type is capabilities', function () {
    $this->mockService->shouldReceive('getCapabilitySummary')
        ->once()
        ->andReturn(['capability' => 'data']);

    $result = $this->tool->execute(['type' => 'capabilities']);

    expect($result)->toHaveKey('capability');
});

test('execute returns gaps when type is gaps', function () {
    $this->mockService->shouldReceive('getAutomationGaps')
        ->once()
        ->andReturn(['gap1', 'gap2']);

    $result = $this->tool->execute(['type' => 'gaps']);

    expect($result)->toHaveKey('gaps')
        ->and($result['gaps'])->toHaveCount(2);
});

test('execute filters items by critical priority', function () {
    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn([
            ['title' => 'Item 1', 'priority' => 'high'],
            ['title' => 'Item 2', 'priority' => 'critical'],
            ['title' => 'Item 3', 'priority' => 'low'],
        ]);

    $result = $this->tool->execute([
        'type' => 'items',
        'priority_filter' => 'critical',
    ]);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['priority'])->toBe('critical');
});

test('execute filters items by high priority', function () {
    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn([
            ['title' => 'Item 1', 'priority' => 'high'],
            ['title' => 'Item 2', 'priority' => 'critical'],
            ['title' => 'Item 3', 'priority' => 'low'],
        ]);

    $result = $this->tool->execute([
        'type' => 'items',
        'priority_filter' => 'high',
    ]);

    expect($result['items'])->toHaveCount(2);
});

test('execute respects limit parameter', function () {
    $items = array_fill(0, 20, ['title' => 'Item', 'priority' => 'high']);

    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn($items);

    $result = $this->tool->execute([
        'type' => 'items',
        'limit' => 5,
    ]);

    expect($result['items'])->toHaveCount(5);
});

test('execute defaults to briefing type', function () {
    $this->mockService->shouldReceive('getMorningBriefing')
        ->once()
        ->andReturn([
            'greeting' => 'Good morning',
            'summary' => 'Test summary',
            'recommendations' => [],
            'top_priorities' => [],
        ]);

    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('greeting');
});

test('execute defaults to 10 items limit', function () {
    $items = array_fill(0, 20, ['title' => 'Item', 'priority' => 'high']);

    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn($items);

    $result = $this->tool->execute(['type' => 'items']);

    expect($result['items'])->toHaveCount(10);
});

test('execute defaults to all priority filter', function () {
    $this->mockService->shouldReceive('getHumanRequiredItems')
        ->once()
        ->andReturn([
            ['title' => 'Item 1', 'priority' => 'high'],
            ['title' => 'Item 2', 'priority' => 'low'],
        ]);

    $result = $this->tool->execute(['type' => 'items']);

    expect($result['items'])->toHaveCount(2);
});

test('execute returns error for unknown type', function () {
    $result = $this->tool->execute(['type' => 'unknown']);

    expect($result)->toHaveKey('error');
});
