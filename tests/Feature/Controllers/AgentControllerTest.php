<?php

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create agent', function () {
    $response = $this->post(route('agents.store'), [
        'name' => 'Test Agent',
        'slug' => 'test-agent',
        'description' => 'Test Description',
        'status' => 'active',
        'model' => 'sonnet',
        'requires_approval' => true,
        'max_budget_usd' => 50,
        'system_prompt' => 'Test system prompt',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Agent created successfully.');

    $this->assertDatabaseHas('agents', [
        'name' => 'Test Agent',
        'slug' => 'test-agent',
        'model' => 'sonnet',
        'max_budget_usd' => 50,
    ]);
});

test('agent creation requires name', function () {
    $response = $this->post(route('agents.store'), [
        'slug' => 'test-agent',
        'model' => 'sonnet',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('agent creation requires unique slug', function () {
    Agent::factory()->create(['slug' => 'existing-slug']);

    $response = $this->post(route('agents.store'), [
        'name' => 'Test Agent',
        'slug' => 'existing-slug',
        'model' => 'sonnet',
    ]);

    $response->assertSessionHasErrors(['slug']);
});

test('agent model must be valid', function () {
    $response = $this->post(route('agents.store'), [
        'name' => 'Test Agent',
        'slug' => 'test-agent',
        'model' => 'invalid-model',
    ]);

    $response->assertSessionHasErrors(['model']);
});

test('agent status must be valid', function () {
    $response = $this->post(route('agents.store'), [
        'name' => 'Test Agent',
        'slug' => 'test-agent',
        'model' => 'sonnet',
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('can update agent', function () {
    $agent = Agent::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-slug',
    ]);

    $response = $this->put(route('agents.update', $agent->slug), [
        'name' => 'New Name',
        'description' => 'Updated Description',
        'status' => 'paused',
        'model' => 'opus',
        'requires_approval' => false,
        'max_budget_usd' => 100,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Agent updated successfully.');

    $agent->refresh();
    expect($agent->name)->toBe('New Name');
    expect($agent->model)->toBe('opus');
});

test('can delete agent', function () {
    $agent = Agent::factory()->create();

    $response = $this->delete(route('agents.destroy', $agent->slug));

    $response->assertRedirect(route('agents.index'));
    $response->assertSessionHas('success', 'Agent deleted successfully.');

    $this->assertDatabaseMissing('agents', [
        'id' => $agent->id,
    ]);
});

test('can update agent status', function () {
    $agent = Agent::factory()->create(['status' => 'paused']);

    $response = $this->put(route('agents.updateStatus', $agent->slug), [
        'status' => 'active',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Agent status updated successfully.');

    $agent->refresh();
    expect($agent->status)->toBe('active');
});

test('can list active agents', function () {
    Agent::factory()->count(3)->create(['status' => 'active']);
    Agent::factory()->create(['status' => 'paused']);

    $response = $this->getJson(route('agents.list'));

    $response->assertStatus(200);
    $response->assertJsonCount(3);
});

test('can get agent runs', function () {
    $agent = Agent::factory()->create();

    $response = $this->getJson(route('agents.runs', $agent));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data',
        'links',
        'meta',
    ]);
});

test('can filter agent runs by status', function () {
    $agent = Agent::factory()->create();

    $response = $this->getJson(route('agents.runs', [
        'agent' => $agent,
        'status' => 'completed',
    ]));

    $response->assertStatus(200);
});

test('can clone agent', function () {
    $agent = Agent::factory()->create([
        'name' => 'Original Agent',
        'slug' => 'original-agent',
    ]);

    $response = $this->post(route('agents.clone', $agent), [
        'name' => 'Cloned Agent',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('agents', [
        'name' => 'Cloned Agent',
        'status' => 'paused',
    ]);
});

test('cloned agent has unique slug', function () {
    $agent = Agent::factory()->create([
        'slug' => 'test-agent',
    ]);

    $this->post(route('agents.clone', $agent), [
        'name' => 'Test Agent',
    ]);

    $cloned = Agent::where('name', 'Test Agent (Copy)')->first();
    expect($cloned->slug)->not->toBe('test-agent');
});

test('can create agent via api', function () {
    $response = $this->postJson(route('agents.apiStore'), [
        'name' => 'API Agent',
        'model' => 'sonnet',
        'description' => 'Created via API',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'message',
        'agent' => ['id', 'name', 'slug', 'model'],
    ]);
});

test('can update agent via api', function () {
    $agent = Agent::factory()->create();

    $response = $this->putJson(route('agents.apiUpdate', $agent), [
        'name' => 'Updated via API',
        'status' => 'active',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'message',
        'agent',
    ]);
});

test('can delete agent via api', function () {
    $agent = Agent::factory()->create();

    $response = $this->deleteJson(route('agents.apiDestroy', $agent));

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Agent deleted successfully']);

    $this->assertDatabaseMissing('agents', [
        'id' => $agent->id,
    ]);
});
