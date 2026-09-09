<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can access cost tracking dashboard', function () {
    $response = $this->get(route('costs.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Costs/Index')
        ->has('overallStats')
        ->has('costByAgent')
        ->has('dailyCosts')
        ->has('costByModel')
        ->has('costBySource')
        ->has('expensiveRuns')
    );
});

test('can filter by time period', function () {
    $response = $this->get(route('costs.index', ['period' => 7]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('period', 7)
    );
});

test('cost tracking shows overall stats', function () {
    $agent = Agent::factory()->create();
    AgentRun::factory()->count(5)->create([
        'agent_id' => $agent->id,
        'cost_usd' => 1.50,
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'created_at' => now(),
    ]);

    $response = $this->get(route('costs.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('overallStats.total_runs', 5)
        ->where('overallStats.total_cost', 7.5)
    );
});

test('cost tracking shows cost by agent', function () {
    $agent1 = Agent::factory()->create(['name' => 'Agent 1']);
    $agent2 = Agent::factory()->create(['name' => 'Agent 2']);

    AgentRun::factory()->create([
        'agent_id' => $agent1->id,
        'cost_usd' => 5.00,
        'created_at' => now(),
    ]);

    AgentRun::factory()->create([
        'agent_id' => $agent2->id,
        'cost_usd' => 3.00,
        'created_at' => now(),
    ]);

    $response = $this->get(route('costs.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->has('costByAgent', 2)
    );
});

test('can get cost summary via api', function () {
    $response = $this->getJson(route('costs.summary'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'total_runs',
        'total_cost',
        'avg_cost',
        'max_cost',
        'total_tokens',
        'cost_change_percent',
    ]);
});

test('cost summary can filter by period', function () {
    $response = $this->getJson(route('costs.summary', ['period' => 7]));

    $response->assertStatus(200);
});

test('unauthenticated user cannot access cost tracking', function () {
    auth()->logout();

    $response = $this->get(route('costs.index'));

    $response->assertRedirect('/login');
});
