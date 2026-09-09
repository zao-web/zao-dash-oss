<?php

use App\Models\Agent;
use App\Models\StrategicGoal;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list weekly plans', function () {
    WeeklyPlan::factory()->count(3)->create();

    $response = $this->get(route('weekly-plans.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Index')
        ->has('plans', 3)
    );
});

test('index shows current plan if exists', function () {
    $currentPlan = WeeklyPlan::factory()->create([
        'week_starting' => now()->startOfWeek(),
        'week_ending' => now()->endOfWeek(),
        'status' => 'active',
    ]);

    $response = $this->get(route('weekly-plans.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Index')
        ->has('currentPlan')
        ->where('currentPlan.id', $currentPlan->id)
    );
});

test('index shows available agents', function () {
    Agent::factory()->count(2)->create([
        'status' => 'active',
        'schedule' => ['frequency' => 'daily'],
    ]);

    $response = $this->get(route('weekly-plans.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Index')
        ->has('availableAgents', 2)
    );
});

test('can view weekly plan', function () {
    $plan = WeeklyPlan::factory()->create();
    WeeklyPlanItem::factory()->count(5)->create([
        'weekly_plan_id' => $plan->id,
    ]);

    $response = $this->get(route('weekly-plans.show', $plan));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Show')
        ->has('plan')
        ->has('items', 5)
        ->has('stats')
    );
});

test('show includes plan stats', function () {
    $plan = WeeklyPlan::factory()->create();
    WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'status' => 'completed',
    ]);
    WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'status' => 'pending',
    ]);
    WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'status' => 'in_progress',
    ]);

    $response = $this->get(route('weekly-plans.show', $plan));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Show')
        ->where('stats.total', 3)
        ->where('stats.completed', 1)
        ->where('stats.pending', 1)
        ->where('stats.in_progress', 1)
    );
});

test('can approve draft plan', function () {
    $plan = WeeklyPlan::factory()->create([
        'status' => 'draft',
    ]);

    $response = $this->post(route('weekly-plans.approve', $plan));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Plan approved. Agent tasks have been scheduled.');

    $plan->refresh();
    expect($plan->status)->toBe('approved');
    expect($plan->approved_at)->not->toBeNull();
    expect($plan->approved_by_id)->toBe($this->user->id);
});

test('cannot approve non-draft plan', function () {
    $plan = WeeklyPlan::factory()->create([
        'status' => 'active',
    ]);

    $response = $this->post(route('weekly-plans.approve', $plan));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Only draft plans can be approved.');
});

test('approving plan creates agent tasks for agent items', function () {
    $plan = WeeklyPlan::factory()->create([
        'status' => 'draft',
    ]);

    $agentItem = WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'owner_type' => 'agent',
        'agent_slug' => 'test-agent',
    ]);

    $humanItem = WeeklyPlanItem::factory()->create([
        'weekly_plan_id' => $plan->id,
        'owner_type' => 'human',
    ]);

    $response = $this->post(route('weekly-plans.approve', $plan));

    $response->assertRedirect();

    // Agent item should have task created
    $agentItem->refresh();
    expect($agentItem->agentTask)->not->toBeNull();

    // Human item should not have task
    $humanItem->refresh();
    expect($humanItem->agentTask)->toBeNull();
});

test('can activate approved plan', function () {
    $plan = WeeklyPlan::factory()->create([
        'status' => 'approved',
        'week_starting' => now()->startOfWeek(),
        'week_ending' => now()->endOfWeek(),
    ]);

    $response = $this->post(route('weekly-plans.activate', $plan));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Plan activated.');

    $plan->refresh();
    expect($plan->status)->toBe('active');
});

test('can update plan item status to in_progress', function () {
    $item = WeeklyPlanItem::factory()->create([
        'status' => 'pending',
    ]);

    $response = $this->patch(route('weekly-plans.items.update', $item), [
        'status' => 'in_progress',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Item updated.');

    $item->refresh();
    expect($item->status)->toBe('in_progress');
    expect($item->started_at)->not->toBeNull();
});

test('can update plan item status to completed', function () {
    $item = WeeklyPlanItem::factory()->create([
        'status' => 'in_progress',
    ]);

    $response = $this->patch(route('weekly-plans.items.update', $item), [
        'status' => 'completed',
        'result' => ['output' => 'Task completed successfully'],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Item updated.');

    $item->refresh();
    expect($item->status)->toBe('completed');
    expect($item->completed_at)->not->toBeNull();
    expect($item->result)->toBe(['output' => 'Task completed successfully']);
});

test('can skip plan item', function () {
    $item = WeeklyPlanItem::factory()->create([
        'status' => 'pending',
    ]);

    $response = $this->patch(route('weekly-plans.items.update', $item), [
        'status' => 'skipped',
        'skip_reason' => 'No longer relevant',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Item updated.');

    $item->refresh();
    expect($item->status)->toBe('skipped');
});

test('item status must be valid', function () {
    $item = WeeklyPlanItem::factory()->create();

    $response = $this->patch(route('weekly-plans.items.update', $item), [
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('unauthenticated user cannot view plans', function () {
    auth()->logout();

    $response = $this->get(route('weekly-plans.index'));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('unauthenticated user cannot approve plan', function () {
    auth()->logout();

    $plan = WeeklyPlan::factory()->create([
        'status' => 'draft',
    ]);

    $response = $this->post(route('weekly-plans.approve', $plan));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('plans are ordered by week starting descending', function () {
    $oldPlan = WeeklyPlan::factory()->create([
        'week_starting' => now()->subWeeks(2)->startOfWeek(),
    ]);
    $newPlan = WeeklyPlan::factory()->create([
        'week_starting' => now()->startOfWeek(),
    ]);

    $response = $this->get(route('weekly-plans.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Index')
        ->where('plans.0.id', $newPlan->id)
        ->where('plans.1.id', $oldPlan->id)
    );
});

test('plan includes associated strategic goal', function () {
    $goal = StrategicGoal::factory()->create();
    $plan = WeeklyPlan::factory()->create([
        'strategic_goal_id' => $goal->id,
    ]);

    $response = $this->get(route('weekly-plans.show', $plan));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('WeeklyPlan/Show')
        ->has('plan.goal')
        ->where('plan.goal.id', $goal->id)
    );
});
