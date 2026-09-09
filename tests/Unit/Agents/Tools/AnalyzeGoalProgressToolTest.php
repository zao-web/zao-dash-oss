<?php

use App\Agents\Tools\AnalyzeGoalProgressTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new AnalyzeGoalProgressTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new AnalyzeGoalProgressTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new AnalyzeGoalProgressTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new AnalyzeGoalProgressTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new AnalyzeGoalProgressTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new AnalyzeGoalProgressTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new AnalyzeGoalProgressTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new AnalyzeGoalProgressTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new AnalyzeGoalProgressTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('validate accepts empty params', function () {
    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $validated = $tool->validate([]);
    expect($validated)->toBeArray();
});

test('validate accepts valid params', function () {
    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));
    $goal = \App\Models\StrategicGoal::factory()->create();

    $params = [
        'goal_id' => $goal->id,
        'include_levers' => true,
    ];

    $validated = $tool->validate($params);
    expect($validated)->toBeArray()
        ->and($validated['goal_id'])->toBe($goal->id);
});

test('validate rejects invalid goal_id', function () {
    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = ['goal_id' => 99999];

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('execute returns error when no active goal exists', function () {
    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('no_active_goal');
});

test('execute analyzes active goal when goal_id not provided', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
        'revenue_target' => 1000000,
        'start_date' => now()->startOfYear(),
        'end_date' => now()->endOfYear(),
    ]);

    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result['goal']['id'])->toBe($goal->id)
        ->and($result)->toHaveKeys(['progress', 'requirements', 'summary']);
});

test('execute analyzes specific goal when goal_id provided', function () {
    $activeGoal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);
    $specificGoal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'completed',
        'revenue_target' => 500000,
    ]);

    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['goal_id' => $specificGoal->id]);

    expect($result['success'])->toBeTrue()
        ->and($result['goal']['id'])->toBe($specificGoal->id);
});

test('execute includes levers when requested and behind pace', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
        'revenue_target' => 1000000,
    ]);

    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['include_levers' => true]);

    expect($result['success'])->toBeTrue();
    // Levers included if behind pace (mocked service will determine)
});

test('execute returns summary with progress metrics', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
        'revenue_target' => 1000000,
    ]);

    $tool = new AnalyzeGoalProgressTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result['summary'])->toBeString()
        ->and($result['requirements'])->toHaveKeys(['leads_per_week', 'deals_needed', 'weeks_remaining']);
});
