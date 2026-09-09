<?php

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('index returns analytics dashboard', function () {
    Agent::factory()->count(3)->create();

    $response = $this->get(route('agent-analytics.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Agents/Analytics')
        ->has('analytics')
        ->has('agents')
    );
});

test('index accepts days parameter', function () {
    $response = $this->get(route('agent-analytics.index', ['days' => 60]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('days', 60)
    );
});

test('data endpoint returns json analytics', function () {
    Agent::factory()->count(2)->create();

    $response = $this->get(route('agent-analytics.data'));

    $response->assertOk();
    $response->assertJson([]);
});

test('data endpoint accepts days parameter', function () {
    $response = $this->get(route('agent-analytics.data', ['days' => 90]));

    $response->assertOk();
});

test('agent analytics returns specific agent data', function () {
    $agent = Agent::factory()->create();

    $response = $this->get(route('agent-analytics.agent', $agent));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Agents/AgentAnalytics')
        ->has('analytics')
    );
});

test('agent data endpoint returns json', function () {
    $agent = Agent::factory()->create();

    $response = $this->get(route('agent-analytics.agentData', $agent));

    $response->assertOk();
    $response->assertJson([]);
});

test('export returns csv file', function () {
    Agent::factory()->count(2)->create();

    $response = $this->get(route('agent-analytics.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv');
    $response->assertHeader('Content-Disposition', 'attachment; filename="agent-analytics.csv"');
});

test('export includes headers', function () {
    $response = $this->get(route('agent-analytics.export'));

    $content = $response->getContent();
    expect($content)->toContain('Agent,Total Runs,Successful,Failed,Success Rate,Total Cost,Avg Cost');
});

test('analytics require authentication', function () {
    auth()->logout();

    $response = $this->get(route('agent-analytics.index'));

    $response->assertRedirect(route('login'));
});
