<?php

use App\Agents\Tools\GetFunnelMetricsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new GetFunnelMetricsTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new GetFunnelMetricsTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new GetFunnelMetricsTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new GetFunnelMetricsTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new GetFunnelMetricsTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new GetFunnelMetricsTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new GetFunnelMetricsTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new GetFunnelMetricsTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new GetFunnelMetricsTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('validate accepts empty params', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $validated = $tool->validate([]);
    expect($validated)->toBeArray();
});

test('validate accepts valid period params', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = [
        'period' => 'last_90',
        'include_trends' => true,
    ];

    $validated = $tool->validate($params);
    expect($validated)->toBeArray()
        ->and($validated['period'])->toBe('last_90');
});

test('validate accepts custom date range', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = [
        'period' => 'custom',
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
    ];

    $validated = $tool->validate($params);
    expect($validated['start_date'])->toBe('2024-01-01')
        ->and($validated['end_date'])->toBe('2024-12-31');
});

test('validate rejects invalid period', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = ['period' => 'invalid'];

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('validate rejects end_date before start_date', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $params = [
        'period' => 'custom',
        'start_date' => '2024-12-31',
        'end_date' => '2024-01-01',
    ];

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('execute returns funnel metrics for default period', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result)->toHaveKeys(['period', 'conversion_rates', 'deal_metrics', 'volume', 'revenue', 'pipeline'])
        ->and($result['period']['label'])->toBe('last_90');
});

test('execute returns metrics for mtd period', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['period' => 'mtd']);

    expect($result['success'])->toBeTrue()
        ->and($result['period']['label'])->toBe('mtd')
        ->and($result['period']['start'])->toBe(now()->startOfMonth()->toDateString());
});

test('execute returns metrics for qtd period', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['period' => 'qtd']);

    expect($result['success'])->toBeTrue()
        ->and($result['period']['label'])->toBe('qtd');
});

test('execute returns metrics for ytd period', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['period' => 'ytd']);

    expect($result['success'])->toBeTrue()
        ->and($result['period']['label'])->toBe('ytd')
        ->and($result['period']['start'])->toBe(now()->startOfYear()->toDateString());
});

test('execute returns metrics for custom date range', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([
        'period' => 'custom',
        'start_date' => '2024-01-01',
        'end_date' => '2024-06-30',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['period']['start'])->toBe('2024-01-01')
        ->and($result['period']['end'])->toBe('2024-06-30');
});

test('execute includes trends when requested', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute(['include_trends' => true]);

    expect($result['success'])->toBeTrue()
        ->and($result)->toHaveKey('trends');
});

test('execute includes interpretation insights', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['success'])->toBeTrue()
        ->and($result)->toHaveKey('interpretation')
        ->and($result['interpretation'])->toBeArray();
});

test('execute returns conversion rates', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['conversion_rates'])->toHaveKeys([
        'new_to_qualified',
        'qualified_to_proposal',
        'proposal_to_negotiation',
        'negotiation_to_won',
        'overall_win_rate',
    ]);
});

test('execute returns deal metrics', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['deal_metrics'])->toHaveKeys(['avg_deal_size', 'avg_sales_cycle_days']);
});

test('execute returns pipeline data', function () {
    $tool = new GetFunnelMetricsTool(app(\App\Services\BusinessIntelligenceService::class));

    $result = $tool->execute([]);

    expect($result['pipeline'])->toHaveKeys(['total_value', 'weighted_value', 'by_stage']);
});
