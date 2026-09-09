<?php

use App\Models\StrategicGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list strategic goals', function () {
    StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
        'fiscal_year' => 2024,
    ]);
    StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
        'fiscal_year' => 2025,
    ]);
    StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
        'fiscal_year' => 2026,
    ]);

    $response = $this->get(route('goals.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Goals/Index')
        ->has('goals', 3)
    );
});

test('can view create goal form', function () {
    $response = $this->get(route('goals.create'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Goals/Create')
        ->has('defaultAssumptions')
        ->has('currentYear')
    );
});

test('can create strategic goal', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'name' => 'FY2025 Revenue Goal',
        'revenue_target' => 1000000,
        'margin_target_pct' => 25,
        'assumptions' => [
            'avg_deal_size' => 25000,
            'win_rate' => 25,
            'sales_cycle_days' => 45,
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Strategic goal created and periods generated.');

    $this->assertDatabaseHas('strategic_goals', [
        'user_id' => $this->user->id,
        'fiscal_year' => 2025,
        'name' => 'FY2025 Revenue Goal',
        'revenue_target' => 1000000,
        'margin_target_pct' => 25,
        'status' => 'active',
    ]);
});

test('goal creation requires fiscal year', function () {
    $response = $this->post(route('goals.store'), [
        'revenue_target' => 1000000,
    ]);

    $response->assertSessionHasErrors(['fiscal_year']);
});

test('goal creation requires revenue target', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
    ]);

    $response->assertSessionHasErrors(['revenue_target']);
});

test('fiscal year must be within valid range', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2020,
        'revenue_target' => 1000000,
    ]);

    $response->assertSessionHasErrors(['fiscal_year']);
});

test('revenue target must be minimum 1000', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'revenue_target' => 500,
    ]);

    $response->assertSessionHasErrors(['revenue_target']);
});

test('margin target must be between 0 and 100', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'revenue_target' => 1000000,
        'margin_target_pct' => 150,
    ]);

    $response->assertSessionHasErrors(['margin_target_pct']);
});

test('can view strategic goal', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('goals.show', $goal));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Goals/Show')
        ->has('goal')
        ->has('progress')
        ->has('requirements')
        ->has('levers')
        ->has('forecast')
        ->has('capacity')
        ->has('periods')
    );
});

test('can update strategic goal', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old Name',
        'revenue_target' => 1000000,
    ]);

    $response = $this->put(route('goals.update', $goal), [
        'name' => 'Updated Name',
        'revenue_target' => 1500000,
        'margin_target_pct' => 30,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Goal updated.');

    $goal->refresh();
    expect($goal->name)->toBe('Updated Name');
    expect($goal->revenue_target)->toEqual(1500000.0);
    expect($goal->margin_target_pct)->toEqual(30.0);
});

test('can update goal status', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    $response = $this->put(route('goals.update', $goal), [
        'status' => 'achieved',
    ]);

    $response->assertRedirect();

    $goal->refresh();
    expect($goal->status)->toBe('achieved');
});

test('goal status must be valid', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->put(route('goals.update', $goal), [
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('can delete strategic goal', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->delete(route('goals.destroy', $goal));

    $response->assertRedirect(route('goals.index'));
    $response->assertSessionHas('success', 'Goal deleted.');

    $this->assertDatabaseMissing('strategic_goals', [
        'id' => $goal->id,
    ]);
});

test('can get goal progress via API', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('api.goals.progress', $goal));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'progress',
        'requirements',
    ]);
});

test('can get goal levers via API', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('api.goals.levers', $goal));

    $response->assertStatus(200);
    $response->assertJsonStructure(['status', 'levers']);
});

test('can get goal forecast via API', function () {
    $goal = StrategicGoal::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson(route('api.goals.forecast', $goal));

    $response->assertStatus(200);
    $response->assertJsonStructure(['current_revenue', 'forecasts']);
});

test('unauthenticated user cannot create goal', function () {
    auth()->logout();

    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'revenue_target' => 1000000,
    ]);

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('unauthenticated user cannot view goals', function () {
    auth()->logout();

    $response = $this->get(route('goals.index'));

    $response->assertStatus(302);
    $response->assertRedirect(route('login'));
});

test('goal defaults name if not provided', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'revenue_target' => 1000000,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('strategic_goals', [
        'fiscal_year' => 2025,
        'name' => 'FY2025 Goal',
    ]);
});

test('goal calculates profit target from revenue and margin', function () {
    $response = $this->post(route('goals.store'), [
        'fiscal_year' => 2025,
        'revenue_target' => 1000000,
        'margin_target_pct' => 25,
    ]);

    $response->assertRedirect();

    $goal = StrategicGoal::where('fiscal_year', 2025)->first();
    expect($goal->profit_target)->toEqual(250000.0);
});
