<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\CreateWebsiteProjectTool;
use App\Mcp\Tools\GetWebsiteProjectTool;
use App\Mcp\Tools\ListWebsiteProjectsTool;
use App\Mcp\Tools\TriggerWebsiteBuildTool;
use App\Models\Agent;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('lists all website projects', function () {
    WebsiteProject::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListWebsiteProjectsTool::class, []);

    $response->assertOk();
    $response->assertSee('website_projects');
});

test('filters website projects by status', function () {
    WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_BUILDING,
        'name' => 'Building Project',
    ]);

    WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_COMPLETE,
        'name' => 'Complete Project',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListWebsiteProjectsTool::class, [
        'status' => 'building',
    ]);

    $response->assertOk();
    $response->assertSee('Building Project');
    $response->assertDontSee('Complete Project');
});

test('filters website projects by project type', function () {
    WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'project_type' => WebsiteProject::TYPE_AUTONOMOUS,
        'name' => 'Autonomous Site',
    ]);

    WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'project_type' => WebsiteProject::TYPE_MIGRATION,
        'name' => 'Migration Site',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListWebsiteProjectsTool::class, [
        'project_type' => 'autonomous',
    ]);

    $response->assertOk();
    $response->assertSee('Autonomous Site');
    $response->assertDontSee('Migration Site');
});

test('gets website project by id', function () {
    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Website',
        'domain' => 'test.example.com',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(GetWebsiteProjectTool::class, [
        'id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('Test Website');
    $response->assertSee('test.example.com');
});

test('gets website project by slug', function () {
    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'My Amazing Website',
        'slug' => 'my-amazing-website',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(GetWebsiteProjectTool::class, [
        'slug' => 'my-amazing-website',
    ]);

    $response->assertOk();
    $response->assertSee('My Amazing Website');
});

test('creates website project', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateWebsiteProjectTool::class, [
        'name' => 'New Website Project',
        'project_type' => 'autonomous',
        'brief' => 'Build a modern business website',
        'domain' => 'new-site.example.com',
    ]);

    $response->assertOk();
    $response->assertSee('success');
    $response->assertSee('New Website Project');

    $this->assertDatabaseHas('website_projects', [
        'name' => 'New Website Project',
        'project_type' => 'autonomous',
        'user_id' => $this->user->id,
        'domain' => 'new-site.example.com',
    ]);
});

test('creates website project with different types', function (string $projectType) {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateWebsiteProjectTool::class, [
        'name' => "Test {$projectType} Project",
        'project_type' => $projectType,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('website_projects', [
        'project_type' => $projectType,
    ]);
})->with(['autonomous', 'guided', 'migration', 'redesign']);

test('trigger website build fails without agent', function () {
    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_CREATED,
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(TriggerWebsiteBuildTool::class, [
        'id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('Website builder agent not found');
});

test('trigger website build fails when project already complete', function () {
    Agent::factory()->create(['slug' => 'website-builder-orchestrator', 'status' => 'active']);

    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_COMPLETE,
        'staging_url' => 'https://staging.example.com',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(TriggerWebsiteBuildTool::class, [
        'id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('already complete');
});

test('trigger website build fails when project in progress', function () {
    Agent::factory()->create(['slug' => 'website-builder-orchestrator', 'status' => 'active']);

    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_BUILDING,
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(TriggerWebsiteBuildTool::class, [
        'id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('already in progress');
});

test('trigger website build starts build for new project', function () {
    Event::fake();

    Agent::factory()->create(['slug' => 'website-builder-orchestrator', 'status' => 'active']);

    $project = WebsiteProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => WebsiteProject::STATUS_CREATED,
        'name' => 'Build Me',
        'slug' => 'build-me',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(TriggerWebsiteBuildTool::class, [
        'id' => $project->id,
    ]);

    $response->assertOk();
    $response->assertSee('success');
    $response->assertSee('Build Me');

    $project->refresh();
    expect($project->status)->toBe(WebsiteProject::STATUS_ANALYZING);
    expect($project->started_at)->not->toBeNull();
});
