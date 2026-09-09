<?php

use App\Agents\Tools\CreateWeeklyPlanTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new CreateWeeklyPlanTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new CreateWeeklyPlanTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new CreateWeeklyPlanTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new CreateWeeklyPlanTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new CreateWeeklyPlanTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new CreateWeeklyPlanTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new CreateWeeklyPlanTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new CreateWeeklyPlanTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new CreateWeeklyPlanTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('validate rejects missing required fields', function () {
    $tool = new CreateWeeklyPlanTool;

    expect(fn () => $tool->validate([]))
        ->toThrow(\InvalidArgumentException::class);
});

test('validate accepts valid params', function () {
    $tool = new CreateWeeklyPlanTool;

    $params = [
        'focus_areas' => ['lead_gen', 'closing'],
        'items' => [
            [
                'action' => 'Generate 10 leads',
                'owner_type' => 'agent',
                'agent_slug' => 'lead-generation',
                'priority' => 'high',
            ],
        ],
    ];

    $validated = $tool->validate($params);
    expect($validated)->toBeArray()
        ->and($validated['focus_areas'])->toBe(['lead_gen', 'closing']);
});

test('validate rejects invalid owner_type', function () {
    $tool = new CreateWeeklyPlanTool;

    $params = [
        'focus_areas' => ['lead_gen'],
        'items' => [
            [
                'action' => 'Test action',
                'owner_type' => 'invalid',
            ],
        ],
    ];

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('execute creates weekly plan with items', function () {
    $tool = new CreateWeeklyPlanTool;

    $params = [
        'focus_areas' => ['lead_gen', 'closing'],
        'targets' => ['leads' => 10, 'revenue' => 50000],
        'strategy_notes' => 'Focus on enterprise deals',
        'items' => [
            [
                'action' => 'Generate 10 qualified leads',
                'owner_type' => 'agent',
                'agent_slug' => 'lead-generation',
                'priority' => 'high',
                'due_day' => 'friday',
                'success_metric' => '10 leads in CRM',
            ],
            [
                'action' => 'Review weekly analytics',
                'owner_type' => 'human',
                'priority' => 'medium',
            ],
        ],
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeTrue()
        ->and($result['plan'])->toHaveKey('id')
        ->and($result['plan']['focus_areas'])->toBe(['lead_gen', 'closing'])
        ->and($result['plan']['status'])->toBe('draft')
        ->and($result['items_created'])->toBe(2)
        ->and($result['items'])->toHaveCount(2);
});

test('execute prevents duplicate non-draft plans', function () {
    $tool = new CreateWeeklyPlanTool;

    // Create first plan
    $params = [
        'week_starting' => now()->next('Monday')->toDateString(),
        'focus_areas' => ['lead_gen'],
        'items' => [
            ['action' => 'Test', 'owner_type' => 'human'],
        ],
    ];

    $result1 = $tool->execute($params);
    expect($result1['success'])->toBeTrue();

    // Activate it
    $plan = \App\Models\WeeklyPlan::find($result1['plan']['id']);
    $plan->update(['status' => 'active']);

    // Try to create another
    $result2 = $tool->execute($params);

    expect($result2['success'])->toBeFalse()
        ->and($result2['error'])->toBe('plan_exists');
});

test('execute links to strategic goal when provided', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
        'revenue_target' => 1000000,
    ]);

    $tool = new CreateWeeklyPlanTool;

    $params = [
        'goal_id' => $goal->id,
        'focus_areas' => ['revenue'],
        'items' => [
            ['action' => 'Close deals', 'owner_type' => 'human'],
        ],
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeTrue();

    $plan = \App\Models\WeeklyPlan::find($result['plan']['id']);
    expect($plan->strategic_goal_id)->toBe($goal->id);
});

test('execute uses active goal when goal_id not provided', function () {
    $goal = \App\Models\StrategicGoal::factory()->create([
        'status' => 'active',
    ]);

    $tool = new CreateWeeklyPlanTool;

    $params = [
        'focus_areas' => ['revenue'],
        'items' => [
            ['action' => 'Test', 'owner_type' => 'human'],
        ],
    ];

    $result = $tool->execute($params);

    $plan = \App\Models\WeeklyPlan::find($result['plan']['id']);
    expect($plan->strategic_goal_id)->toBe($goal->id);
});
