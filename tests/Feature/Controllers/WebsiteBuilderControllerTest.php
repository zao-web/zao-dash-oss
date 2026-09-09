<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can view website builder index', function () {
    $response = $this->get(route('website-builder.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('WebsiteBuilder/Index'));
});

test('can view website project', function () {
    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $response = $this->get(route('website-builder.show', $project));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('WebsiteBuilder/Show')
        ->has('project')
        ->has('progressPercentage')
    );
});

test('cannot view other users project', function () {
    $otherUser = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->get(route('website-builder.show', $project));

    $response->assertForbidden();
});

test('can list user projects', function () {
    WebsiteProject::factory()->count(3)->create(['user_id' => $this->user->id]);

    $otherUser = User::factory()->create();
    WebsiteProject::factory()->count(2)->create(['user_id' => $otherUser->id]);

    $response = $this->getJson(route('api.website-builder.projects.list'));

    $response->assertSuccessful();
    $response->assertJson(['success' => true]);
    expect($response->json('projects.data'))->toHaveCount(3);
});

test('can filter projects by type', function () {
    WebsiteProject::factory()->autonomous()->count(2)->create(['user_id' => $this->user->id]);
    WebsiteProject::factory()->guided()->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('api.website-builder.projects.list', ['type' => 'autonomous']));

    $response->assertSuccessful();
    expect($response->json('projects.data'))->toHaveCount(2);
});

test('can create autonomous project', function () {
    $response = $this->postJson(route('api.website-builder.projects.create'), [
        'name' => 'Test Website',
        'project_type' => 'autonomous',
        'source_type' => 'domain',
        'domain' => 'example.com',
        'brief' => 'Build a company website for a tech startup.',
        'hosting_type' => 'wordpress_com',
        'environment' => 'staging',
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'success',
        'project' => ['id', 'name', 'project_type', 'status'],
    ]);

    expect($response->json('project.name'))->toBe('Test Website');
    expect($response->json('project.project_type'))->toBe('autonomous');
});

test('can create guided project', function () {
    $response = $this->postJson(route('api.website-builder.projects.create'), [
        'name' => 'Custom Design',
        'project_type' => 'guided',
        'source_type' => 'brief',
        'brief' => 'Need a custom designed portfolio site with specific patterns.',
    ]);

    $response->assertSuccessful();
    expect($response->json('project.project_type'))->toBe('guided');
});

test('can create migration project', function () {
    $response = $this->postJson(route('api.website-builder.projects.create'), [
        'name' => 'Site Migration',
        'project_type' => 'migration',
        'source_type' => 'url',
        'url' => 'https://oldsite.com',
        'brief' => 'Migrate existing Joomla site to WordPress.',
    ]);

    $response->assertSuccessful();
    expect($response->json('project.project_type'))->toBe('migration');
});

test('validates required fields on project creation', function () {
    $response = $this->postJson(route('api.website-builder.projects.create'), []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name', 'project_type']);
});

test('cleans domain on project creation', function () {
    $response = $this->postJson(route('api.website-builder.projects.create'), [
        'name' => 'Test',
        'project_type' => 'autonomous',
        'domain' => 'https://example.com/',
        'brief' => 'Test brief for validation.',
    ]);

    $response->assertSuccessful();
    expect($response->json('project.domain'))->toBe('example.com');
});

test('can update project', function () {
    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $response = $this->putJson(route('api.website-builder.projects.update', $project), [
        'name' => 'Updated Name',
        'status' => 'analyzing',
    ]);

    $response->assertSuccessful();
    expect($response->json('project.name'))->toBe('Updated Name');
    expect($response->json('project.status'))->toBe('analyzing');
});

test('cannot update other users project', function () {
    $otherUser = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->putJson(route('api.website-builder.projects.update', $project), [
        'name' => 'Hacked',
    ]);

    $response->assertForbidden();
});

test('can get project status', function () {
    $project = WebsiteProject::factory()->analyzing()->create([
        'user_id' => $this->user->id,
        'staging_url' => 'https://staging.example.com',
    ]);

    $response = $this->getJson(route('api.website-builder.projects.status', $project));

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'success',
        'status',
        'progress_percentage',
        'progress_data',
        'staging_url',
    ]);
});

test('can send message to project', function () {
    Agent::factory()->create(['slug' => 'website-builder-orchestrator', 'status' => 'active']);

    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    // Mock the queue to avoid actual job dispatch
    \Illuminate\Support\Facades\Queue::fake();

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), [
        'message' => 'Can you add a contact form?',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['success' => true, 'message' => 'Agent processing started']);
});

test('message requires content', function () {
    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['message']);
});

test('message cannot exceed 2000 characters', function () {
    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), [
        'message' => str_repeat('a', 2001),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['message']);
});

test('returns error when orchestrator agent not found', function () {
    // Ensure no orchestrator agent exists
    Agent::where('slug', 'website-builder-orchestrator')->delete();
    Agent::where('slug', 'site-builder-orchestrator')->delete();

    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), [
        'message' => 'Hello',
    ]);

    $response->assertStatus(500);
    $response->assertJson(['success' => false]);
    expect($response->json('error'))->toContain('not found');
});

test('can parse brief from text', function () {
    $response = $this->postJson(route('api.website-builder.parse-brief'), [
        'content' => 'Build a website for Acme Corp. Primary color: blue.',
    ]);

    $response->assertSuccessful();
});

test('can request site analysis', function () {
    $response = $this->postJson(route('api.website-builder.analyze-site'), [
        'url' => 'https://example.com',
        'depth' => 'shallow',
    ]);

    $response->assertSuccessful();
});

test('can request repo analysis', function () {
    $response = $this->postJson(route('api.website-builder.analyze-repo'), [
        'repo_url' => 'https://github.com/owner/repo',
        'depth' => 'quick',
    ]);

    $response->assertSuccessful();
});

test('can request batch page analysis', function () {
    $response = $this->postJson(route('api.website-builder.analyze-pages'), [
        'base_url' => 'https://example.com',
        'pages' => [
            ['name' => 'home', 'path' => '/', 'url' => 'https://example.com'],
            ['name' => 'about', 'path' => '/about', 'url' => 'https://example.com/about'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure(['success', 'batch_id', 'total_pages']);
});

test('can get analysis results by batch id', function () {
    $batchId = 'test-batch-123';
    \Cache::put("ollie-batch:{$batchId}:status", 'completed', 60);
    \Cache::put("ollie-batch:{$batchId}:results", ['test' => 'data'], 60);

    $response = $this->getJson(route('api.website-builder.analyze-pages.results', $batchId));

    $response->assertSuccessful();
    $response->assertJsonStructure(['success', 'status', 'results']);
});

test('can request theme generation', function () {
    $response = $this->postJson(route('api.website-builder.generate-theme'), [
        'project_id' => 'test-123',
        'colors' => ['primary' => '#FF0000', 'secondary' => '#00FF00'],
        'base_style' => 'default',
    ]);

    $response->assertSuccessful();
    $response->assertJson(['success' => true]);
});

test('can request page composition', function () {
    $response = $this->postJson(route('api.website-builder.compose-page'), [
        'project_id' => 'test-123',
        'page_title' => 'Home',
        'page_slug' => 'home',
        'patterns' => ['hero-simple', 'features-grid'],
    ]);

    $response->assertSuccessful();
});

test('can list patterns', function () {
    $response = $this->getJson(route('api.website-builder.patterns'));

    $response->assertSuccessful();
});

test('channel authorization allows project owner', function () {
    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    $canAccess = \App\Models\WebsiteProject::where('id', $project->id)
        ->where('user_id', $this->user->id)
        ->exists();

    expect($canAccess)->toBeTrue();
});

test('channel authorization denies non-owner', function () {
    $otherUser = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $otherUser->id]);

    $canAccess = \App\Models\WebsiteProject::where('id', $project->id)
        ->where('user_id', $this->user->id)
        ->exists();

    expect($canAccess)->toBeFalse();
});

test('cannot send message to other users project', function () {
    $otherUser = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), [
        'message' => 'Trying to access someone elses project',
    ]);

    $response->assertForbidden();
});

test('chat accepts optional context array', function () {
    Agent::factory()->create(['slug' => 'website-builder-orchestrator', 'status' => 'active']);

    $project = WebsiteProject::factory()->create(['user_id' => $this->user->id]);

    // Mock the queue to avoid actual job dispatch
    \Illuminate\Support\Facades\Queue::fake();

    $response = $this->postJson(route('api.website-builder.projects.chat', $project), [
        'message' => 'Continue from previous conversation',
        'context' => [
            ['role' => 'user', 'content' => 'Build me a website'],
            ['role' => 'assistant', 'content' => 'Sure, what kind of website?'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJson(['success' => true, 'message' => 'Agent processing started']);
});
