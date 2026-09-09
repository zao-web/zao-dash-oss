<?php

use App\Models\Agent;
use App\Models\AgentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can list agent templates', function () {
    AgentTemplate::factory()->count(3)->create(['is_public' => true]);

    $response = $this->getJson(route('agent-templates.index'));

    $response->assertStatus(200);
    $response->assertJsonCount(3);
});

test('can filter templates by category', function () {
    AgentTemplate::factory()->create(['category' => 'marketing', 'is_public' => true]);
    AgentTemplate::factory()->create(['category' => 'sales', 'is_public' => true]);

    $response = $this->getJson(route('agent-templates.index', ['category' => 'marketing']));

    $response->assertStatus(200);
    $response->assertJsonCount(1);
});

test('only shows public templates by default', function () {
    AgentTemplate::factory()->create(['is_public' => true]);
    AgentTemplate::factory()->create(['is_public' => false]);

    $response = $this->getJson(route('agent-templates.index'));

    $response->assertStatus(200);
    $response->assertJsonCount(1);
});

test('can view single template', function () {
    $template = AgentTemplate::factory()->create();

    $response = $this->getJson(route('agent-templates.show', $template));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'id',
        'name',
        'slug',
        'category',
        'default_model',
    ]);
});

test('can create agent from template', function () {
    $template = AgentTemplate::factory()->create([
        'default_model' => 'sonnet',
        'system_prompt_template' => 'Test prompt',
    ]);

    $response = $this->postJson(route('agent-templates.createAgent', $template), [
        'name' => 'New Agent',
        'slug' => 'new-agent',
        'description' => 'From template',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'message',
        'agent' => ['id', 'name', 'slug'],
    ]);

    $this->assertDatabaseHas('agents', [
        'name' => 'New Agent',
        'slug' => 'new-agent',
    ]);
});

test('can preview template configuration', function () {
    $template = AgentTemplate::factory()->create([
        'system_prompt_template' => 'Hello {{name}}',
        'config_schema' => ['name' => 'string'],
    ]);

    $response = $this->postJson(route('agent-templates.preview', $template), [
        'config' => ['name' => 'World'],
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'template',
        'preview' => ['model', 'budget', 'requires_approval', 'tools', 'system_prompt'],
        'config_schema',
    ]);
});

test('can get template categories', function () {
    AgentTemplate::factory()->create(['category' => 'marketing', 'is_public' => true]);
    AgentTemplate::factory()->create(['category' => 'sales', 'is_public' => true]);
    AgentTemplate::factory()->create(['category' => 'marketing', 'is_public' => true]);

    $response = $this->getJson(route('agent-templates.categories'));

    $response->assertStatus(200);
    $response->assertJsonCount(2);
});

test('can create new template', function () {
    $response = $this->postJson(route('agent-templates.store'), [
        'name' => 'Custom Template',
        'slug' => 'custom-template',
        'description' => 'Test template',
        'category' => 'custom',
        'default_model' => 'sonnet',
        'default_budget_usd' => 10,
        'default_requires_approval' => true,
        'is_public' => false,
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'message',
        'template' => ['id', 'name', 'slug'],
    ]);
});

test('template creation requires unique slug', function () {
    AgentTemplate::factory()->create(['slug' => 'existing']);

    $response = $this->postJson(route('agent-templates.store'), [
        'name' => 'Test',
        'slug' => 'existing',
        'category' => 'test',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['slug']);
});

test('can update template', function () {
    $template = AgentTemplate::factory()->create(['name' => 'Old Name']);

    $response = $this->putJson(route('agent-templates.update', $template), [
        'name' => 'New Name',
        'category' => 'updated',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['message', 'template']);

    $template->refresh();
    expect($template->name)->toBe('New Name');
});

test('can delete template', function () {
    $template = AgentTemplate::factory()->create();

    $response = $this->deleteJson(route('agent-templates.destroy', $template));

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Template deleted successfully']);

    $this->assertDatabaseMissing('agent_templates', [
        'id' => $template->id,
    ]);
});

test('can get featured templates', function () {
    AgentTemplate::factory()->count(10)->create([
        'is_public' => true,
        'usage_count' => fn () => rand(1, 100),
    ]);

    $response = $this->getJson(route('agent-templates.featured', ['limit' => 5]));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'featured',
        'total_templates',
        'total_categories',
    ]);
    expect(count($response->json('featured')))->toBeLessThanOrEqual(5);
});

test('can search templates', function () {
    AgentTemplate::factory()->create([
        'name' => 'Marketing Automation',
        'is_public' => true,
    ]);
    AgentTemplate::factory()->create([
        'name' => 'Sales Outreach',
        'is_public' => true,
    ]);

    $response = $this->getJson(route('agent-templates.search', ['q' => 'Marketing']));

    $response->assertStatus(200);
    $response->assertJsonStructure(['results', 'query', 'count']);
    expect(count($response->json('results')))->toBe(1);
});

test('can create template from agent', function () {
    $agent = Agent::factory()->create([
        'name' => 'Source Agent',
        'model' => 'sonnet',
    ]);

    $response = $this->postJson(route('agent-templates.createFromAgent', $agent), [
        'name' => 'Template from Agent',
        'slug' => 'template-from-agent',
        'category' => 'custom',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['message', 'template']);

    $this->assertDatabaseHas('agent_templates', [
        'name' => 'Template from Agent',
        'default_model' => 'sonnet',
    ]);
});

test('can duplicate template', function () {
    $template = AgentTemplate::factory()->create([
        'name' => 'Original',
        'slug' => 'original',
    ]);

    $response = $this->postJson(route('agent-templates.duplicate', $template), [
        'name' => 'Duplicated',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['message', 'template']);

    $this->assertDatabaseHas('agent_templates', [
        'name' => 'Duplicated',
        'is_public' => false,
    ]);
});
