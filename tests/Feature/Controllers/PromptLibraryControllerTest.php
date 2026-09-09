<?php

use App\Models\Agent;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('index returns prompt templates', function () {
    PromptTemplate::factory()->count(3)->create();

    $response = $this->get(route('prompts.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Prompts/Index')
        ->has('templates', 3)
    );
});

test('index filters by category', function () {
    PromptTemplate::factory()->count(2)->create(['category' => 'task']);
    PromptTemplate::factory()->count(3)->create(['category' => 'system']);

    $response = $this->get(route('prompts.index', ['category' => 'task']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('templates', 2)
    );
});

test('index filters by agent', function () {
    $agent = Agent::factory()->create();

    PromptTemplate::factory()->count(2)->create(['agent_id' => $agent->id]);
    PromptTemplate::factory()->count(3)->create();

    $response = $this->get(route('prompts.index', ['agent_id' => $agent->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('templates', 2)
    );
});

test('index searches by name', function () {
    PromptTemplate::factory()->create(['name' => 'Test Prompt']);
    PromptTemplate::factory()->create(['name' => 'Other Prompt']);

    $response = $this->get(route('prompts.index', ['search' => 'Test']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('templates', 1)
    );
});

test('show returns prompt template details', function () {
    $template = PromptTemplate::factory()->create();

    $response = $this->get(route('prompts.show', $template));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Prompts/Show')
        ->has('template')
    );
});

test('can create prompt template', function () {
    $response = $this->post(route('prompts.store'), [
        'name' => 'New Prompt',
        'description' => 'Test description',
        'category' => 'task',
        'content' => 'Prompt content here',
        'tags' => ['tag1', 'tag2'],
        'is_public' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('prompt_templates', [
        'name' => 'New Prompt',
        'category' => 'task',
    ]);
});

test('create requires name', function () {
    $response = $this->post(route('prompts.store'), [
        'category' => 'task',
        'content' => 'Content',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('create requires valid category', function () {
    $response = $this->post(route('prompts.store'), [
        'name' => 'Test',
        'category' => 'invalid-category',
        'content' => 'Content',
    ]);

    $response->assertSessionHasErrors(['category']);
});

test('create requires content', function () {
    $response = $this->post(route('prompts.store'), [
        'name' => 'Test',
        'category' => 'task',
    ]);

    $response->assertSessionHasErrors(['content']);
});

test('can update prompt template', function () {
    $template = PromptTemplate::factory()->create(['name' => 'Old Name']);

    $response = $this->put(route('prompts.update', $template), [
        'name' => 'New Name',
        'description' => 'Updated',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $template->refresh();
    expect($template->name)->toBe('New Name');
});

test('updating content creates new version', function () {
    $template = PromptTemplate::factory()->create(['content' => 'Old content']);

    $response = $this->put(route('prompts.update', $template), [
        'content' => 'New content',
        'version_description' => 'Updated content',
    ]);

    expect(PromptVersion::where('prompt_template_id', $template->id)->count())->toBeGreaterThan(0);
});

test('can delete prompt template', function () {
    $template = PromptTemplate::factory()->create();

    $response = $this->delete(route('prompts.destroy', $template));

    $response->assertRedirect(route('prompts.index'));
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('prompt_templates', [
        'id' => $template->id,
    ]);
});

test('can create new version', function () {
    $template = PromptTemplate::factory()->create();

    $response = $this->post(route('prompts.createVersion', $template), [
        'content' => 'Version 2 content',
        'description' => 'Second version',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('can activate version', function () {
    $template = PromptTemplate::factory()->create();
    $version = PromptVersion::factory()->create([
        'prompt_template_id' => $template->id,
        'is_active' => false,
    ]);

    $response = $this->post(route('prompts.activateVersion', [$template, $version]));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('cannot activate version from different template', function () {
    $template1 = PromptTemplate::factory()->create();
    $template2 = PromptTemplate::factory()->create();
    $version = PromptVersion::factory()->create(['prompt_template_id' => $template2->id]);

    $response = $this->post(route('prompts.activateVersion', [$template1, $version]));

    $response->assertNotFound();
});

test('can get version content', function () {
    $template = PromptTemplate::factory()->create();
    $version = PromptVersion::factory()->create(['prompt_template_id' => $template->id]);

    $response = $this->get(route('prompts.getVersionContent', [$template, $version]));

    $response->assertOk();
    $response->assertJsonStructure([
        'version_number',
        'content',
        'variables',
        'metrics',
    ]);
});

test('can set ab test weights', function () {
    $template = PromptTemplate::factory()->create();
    $v1 = PromptVersion::factory()->create(['prompt_template_id' => $template->id]);
    $v2 = PromptVersion::factory()->create(['prompt_template_id' => $template->id]);

    $response = $this->post(route('prompts.setAbTestWeights', $template), [
        'weights' => [
            ['version_id' => $v1->id, 'weight' => 70],
            ['version_id' => $v2->id, 'weight' => 30],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $v1->refresh();
    expect($v1->ab_test_weight)->toBe(70);
});

test('can preview prompt with variables', function () {
    $template = PromptTemplate::factory()->create([
        'content' => 'Hello {{name}}',
    ]);

    $response = $this->post(route('prompts.preview', $template), [
        'variables' => ['name' => 'John'],
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'rendered',
        'variables_used',
    ]);
});

test('can duplicate prompt template', function () {
    $template = PromptTemplate::factory()->create(['name' => 'Original']);

    $response = $this->post(route('prompts.duplicate', $template));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(PromptTemplate::where('name', 'LIKE', 'Original (Copy)%')->count())->toBe(1);
});

test('prompts require authentication', function () {
    auth()->logout();

    $response = $this->get(route('prompts.index'));

    $response->assertRedirect(route('login'));
});
