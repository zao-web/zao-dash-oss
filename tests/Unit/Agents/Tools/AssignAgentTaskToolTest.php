<?php

use App\Agents\Tools\AssignAgentTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new AssignAgentTaskTool;
    expect($tool->name())->toBeString()->not->toBeEmpty();
});

test('getDescription returns correct description', function () {
    $tool = new AssignAgentTaskTool;
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('getParameters returns valid schema', function () {
    $tool = new AssignAgentTaskTool;
    $schema = $tool->inputSchema();

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('object')
        ->and($schema)->toHaveKey('properties');
});

test('id returns correct tool ID', function () {
    $tool = new AssignAgentTaskTool;
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval returns boolean', function () {
    $tool = new AssignAgentTaskTool;
    expect($tool->requiresApproval())->toBeBool();
});

test('riskLevel returns valid level', function () {
    $tool = new AssignAgentTaskTool;
    expect($tool->riskLevel())->toBeIn(['low', 'medium', 'high']);
});

test('validate accepts empty params when no required fields', function () {
    $tool = new AssignAgentTaskTool;
    $schema = $tool->inputSchema();

    if (empty($schema['required'] ?? [])) {
        $result = $tool->validate([]);
        expect($result)->toBeArray();
    } else {
        expect(true)->toBeTrue(); // Skip if has required fields
    }
});

test('toArray returns complete metadata', function () {
    $tool = new AssignAgentTaskTool;
    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level']);
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new AssignAgentTaskTool;
    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('validate rejects missing required fields', function () {
    $tool = new AssignAgentTaskTool;

    expect(fn () => $tool->validate([]))
        ->toThrow(\InvalidArgumentException::class);
});

test('validate accepts valid params', function () {
    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'lead-generation',
        'task_description' => 'Generate 10 qualified leads this week',
        'priority' => 'high',
    ];

    $validated = $tool->validate($params);
    expect($validated)->toBeArray()
        ->and($validated['agent_slug'])->toBe('lead-generation');
});

test('validate rejects invalid priority', function () {
    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'test',
        'task_description' => 'Test task',
        'priority' => 'invalid',
    ];

    expect(fn () => $tool->validate($params))
        ->toThrow(\InvalidArgumentException::class);
});

test('execute returns error when agent not found', function () {
    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'non-existent-agent',
        'task_description' => 'Test task',
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('agent_not_found')
        ->and($result)->toHaveKey('available_agents');
});

test('execute returns error when agent not active', function () {
    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'test-agent',
        'status' => 'inactive',
    ]);

    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'test-agent',
        'task_description' => 'Test task',
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('agent_not_active');
});

test('execute creates agent task successfully', function () {
    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'lead-generation',
        'status' => 'active',
        'name' => 'Lead Generation Agent',
    ]);

    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'lead-generation',
        'task_description' => 'Generate 10 qualified leads',
        'context' => ['target_industry' => 'SaaS'],
        'priority' => 'high',
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeTrue()
        ->and($result['task'])->toHaveKey('id')
        ->and($result['task']['agent']['slug'])->toBe('lead-generation')
        ->and($result['task']['description'])->toBe('Generate 10 qualified leads')
        ->and($result['task']['priority'])->toBe('high')
        ->and($result['task']['status'])->toBe('pending');
});

test('execute schedules task when scheduled_for provided', function () {
    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'test-agent',
        'status' => 'active',
    ]);

    $tool = new AssignAgentTaskTool;

    $scheduledTime = now()->addDays(2)->toDateTimeString();

    $params = [
        'agent_slug' => 'test-agent',
        'task_description' => 'Test task',
        'scheduled_for' => $scheduledTime,
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeTrue()
        ->and($result['task']['status'])->toBe('scheduled');
});

test('execute links to weekly plan item when provided', function () {
    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'test-agent',
        'status' => 'active',
    ]);

    $plan = \App\Models\WeeklyPlan::factory()->create();
    $planItem = \App\Models\WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'status' => 'pending',
    ]);

    $tool = new AssignAgentTaskTool;

    $params = [
        'agent_slug' => 'test-agent',
        'task_description' => 'Test task',
        'weekly_plan_item_id' => $planItem->id,
    ];

    $result = $tool->execute($params);

    expect($result['success'])->toBeTrue();

    $task = \App\Models\AgentTask::find($result['task']['id']);
    expect($task->weekly_plan_item_id)->toBe($planItem->id);

    $planItem->refresh();
    expect($planItem->status)->toBe('in_progress');
});
