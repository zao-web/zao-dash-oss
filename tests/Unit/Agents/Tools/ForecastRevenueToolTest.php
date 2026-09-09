<?php

use App\Agents\Tools\ForecastRevenueTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new ForecastRevenueTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new ForecastRevenueTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new ForecastRevenueTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new ForecastRevenueTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new ForecastRevenueTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new ForecastRevenueTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new ForecastRevenueTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new ForecastRevenueTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new ForecastRevenueTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('validate accepts empty params', function () {
    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $validated = $tool->validate([]);
    expect($validated)->toBeArray();
});

test('validate accepts valid params', function () {
    $goal = \App\Models\StrategicGoal::factory()->create();
    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = [
        'goal_id' => $goal->id,
        'horizon_days' => 90,
    ];

    $validated = $tool->validate($params);
    expect($validated['horizon_days'])->toBe(90);
});

test('validate rejects invalid horizon_days', function () {
    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = ['horizon_days' => 5]; // Below min of 7

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('validate rejects horizon_days over maximum', function () {
    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = ['horizon_days' => 400]; // Above max of 365

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('execute returns error when no active goal exists', function () {
    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('no_active_goal');
});

test('execute forecasts revenue for active goal', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
        'revenue_target' => 1000000,
    ]);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result['goal']['id'])->toBe($goal->id)
        ->and($result)->toHaveKeys(['current_state', 'forecasts', 'year_end', 'analysis']);
});

test('execute forecasts for specific goal', function () {
    $activeGoal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);
    $specificGoal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'completed',
        'revenue_target' => 500000,
    ]);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['goal_id' => $specificGoal->id]);

    expect($result['success'])->toBeTrue()
        ->and($result['goal']['id'])->toBe($specificGoal->id);
});

test('execute returns 30/60/90 day forecasts', function () {
    $goal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['forecasts'])->toHaveKeys(['30_day', '60_day', '90_day'])
        ->and($result['forecasts']['30_day'])->toHaveKeys(['velocity', 'pipeline', 'blended'])
        ->and($result['forecasts']['60_day'])->toHaveKeys(['velocity', 'pipeline', 'blended'])
        ->and($result['forecasts']['90_day'])->toHaveKeys(['velocity', 'pipeline', 'blended']);
});

test('execute returns year end forecast', function () {
    $goal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['year_end'])->toHaveKeys([
        'days_remaining',
        'velocity_forecast',
        'pipeline_forecast',
        'will_hit_target',
        'gap',
    ]);
});

test('execute returns current state metrics', function () {
    $goal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['current_state'])->toHaveKeys([
        'revenue_actual',
        'weighted_pipeline',
        'daily_velocity',
    ]);
});

test('execute includes analysis insights', function () {
    $goal = \App\Models\StrategicGoal::factory()->create(['status' => 'active']);

    $tool = new ForecastRevenueTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['analysis'])->toBeArray()
        ->and($result['analysis'])->not->toBeEmpty();
});
