<?php

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new WeeklyPlanItem)->getGuarded())->toBe([]);
});

test('casts expected_outcome to array', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'expected_outcome' => ['metric' => 'value'],
        'status' => 'pending',
    ]);

    expect($item->expected_outcome)->toBeArray()
        ->and($item->expected_outcome)->toBe(['metric' => 'value']);
});

test('casts result to array', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'result' => ['output' => 'success'],
        'status' => 'pending',
    ]);

    expect($item->result)->toBeArray()
        ->and($item->result)->toBe(['output' => 'success']);
});

test('belongs to weekly plan relationship', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item->weeklyPlan())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to agent run relationship', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('agent accessor returns null for human task', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item->agent)->toBeNull();
});

test('agent accessor returns agent for agent task', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'agent',
        'agent_slug' => 'test-agent',
        'status' => 'pending',
    ]);

    expect($item->agent)->not->toBeNull()
        ->and($item->agent->slug)->toBe('test-agent');
});

test('is_human_task accessor returns true for human owner', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => WeeklyPlanItem::OWNER_HUMAN,
        'status' => 'pending',
    ]);

    expect($item->is_human_task)->toBeTrue();
});

test('is_agent_task accessor returns true for agent owner', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => WeeklyPlanItem::OWNER_AGENT,
        'agent_slug' => 'test-agent',
        'status' => 'pending',
    ]);

    expect($item->is_agent_task)->toBeTrue();
});

test('due_date accessor returns null when no due_day', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item->due_date)->toBeNull();
});

test('due_date accessor calculates correct date for monday', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'due_day' => 'monday',
        'status' => 'pending',
    ]);

    expect($item->due_date->toDateString())->toBe('2025-01-06');
});

test('due_date accessor calculates correct date for friday', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'due_day' => 'friday',
        'status' => 'pending',
    ]);

    expect($item->due_date->toDateString())->toBe('2025-01-10');
});

test('is_overdue accessor returns false for completed task', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'due_day' => 'monday',
        'status' => WeeklyPlanItem::STATUS_COMPLETED,
    ]);

    expect($item->is_overdue)->toBeFalse();
});

test('start method sets status to in_progress', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    $item->start();

    expect($item->fresh()->status)->toBe(WeeklyPlanItem::STATUS_IN_PROGRESS);
});

test('complete method sets status and result', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'in_progress',
    ]);

    $result = ['output' => 'success'];
    $item->complete($result);

    expect($item->fresh()->status)->toBe(WeeklyPlanItem::STATUS_COMPLETED)
        ->and($item->fresh()->result)->toBe($result);
});

test('skip method sets status and reason', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    $reason = 'Not needed anymore';
    $item->skip($reason);

    expect($item->fresh()->status)->toBe(WeeklyPlanItem::STATUS_SKIPPED)
        ->and($item->fresh()->result['skipped_reason'])->toBe($reason);
});

test('createAgentTask returns null for human task', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item->createAgentTask())->toBeNull();
});

test('createAgentTask creates task for agent item', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Agent task action',
        'owner_type' => 'agent',
        'agent_slug' => 'test-agent',
        'success_metric' => 'Test metric',
        'priority' => WeeklyPlanItem::PRIORITY_HIGH,
        'status' => 'pending',
    ]);

    $task = $item->createAgentTask();

    expect($task)->toBeInstanceOf(AgentTask::class)
        ->and($task->agent_id)->toBe($agent->id)
        ->and($task->weekly_plan_item_id)->toBe($item->id)
        ->and($task->task_description)->toBe('Agent task action')
        ->and($task->priority)->toBe('high');
});

test('mapPriority maps critical to urgent', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Critical task',
        'owner_type' => 'agent',
        'agent_slug' => 'test-agent',
        'priority' => WeeklyPlanItem::PRIORITY_CRITICAL,
        'status' => 'pending',
    ]);

    $task = $item->createAgentTask();

    expect($task->priority)->toBe('urgent');
});

test('mapPriority maps low to low', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Low priority task',
        'owner_type' => 'agent',
        'agent_slug' => 'test-agent',
        'priority' => WeeklyPlanItem::PRIORITY_LOW,
        'status' => 'pending',
    ]);

    $task = $item->createAgentTask();

    expect($task->priority)->toBe('low');
});

test('can be created directly', function () {
    $plan = WeeklyPlan::create([
        'week_starting' => '2025-01-06',
        'status' => 'draft',
    ]);

    $item = WeeklyPlanItem::create([
        'weekly_plan_id' => $plan->id,
        'action' => 'Test action',
        'owner_type' => 'human',
        'status' => 'pending',
    ]);

    expect($item)->toBeInstanceOf(WeeklyPlanItem::class)
        ->and($item->exists)->toBeTrue();
});
