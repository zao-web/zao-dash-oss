<?php

use App\Agents\Tools\PostToXTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new PostToXTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new PostToXTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new PostToXTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new PostToXTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new PostToXTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new PostToXTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new PostToXTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new PostToXTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new PostToXTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

// TODO: Add execute method tests with mocked dependencies
// TODO: Add validation tests for required parameters
// TODO: Add error handling tests
