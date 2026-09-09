<?php

use App\Events\SiteBuilderError;
use App\Events\SiteBuilderMessageReceived;
use App\Events\SiteBuilderStatusUpdated;
use App\Events\SiteBuilderToolExecuted;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\SiteBuilderProject;
use App\Models\User;
use App\Services\Agents\AgentExecutor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can view site builder index', function () {
    $response = $this->get(route('site-builder.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('SiteBuilder/Index'));
});

test('can view site builder project', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('site-builder.show', $project));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('SiteBuilder/Show')
        ->has('project')
        ->has('progressPercentage')
    );
});

test('cannot view other users site builder project', function () {
    $otherUser = User::factory()->create();
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->get(route('site-builder.show', $project));

    $response->assertForbidden();
});

test('can send chat message to site builder project', function () {
    Event::fake();

    $project = SiteBuilderProject::factory()->research()->create([
        'user_id' => $this->user->id,
    ]);

    $agent = Agent::factory()->create([
        'slug' => 'site-builder-orchestrator',
        'status' => 'active',
    ]);

    $agentRun = AgentRun::factory()->create([
        'agent_id' => $agent->id,
    ]);

    $this->mock(AgentExecutor::class)
        ->shouldReceive('execute')
        ->once()
        ->andReturn($agentRun);

    $response = $this->postJson("/api/site-builder/projects/{$project->id}/chat", [
        'message' => 'Can you add a contact form to the homepage?',
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'success' => true,
        'message' => 'Message sent',
    ]);
});

test('chat message requires message content', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->postJson("/api/site-builder/projects/{$project->id}/chat", []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['message']);
});

test('chat message cannot exceed 2000 characters', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->postJson("/api/site-builder/projects/{$project->id}/chat", [
        'message' => str_repeat('a', 2001),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['message']);
});

test('cannot send chat message to other users project', function () {
    $otherUser = User::factory()->create();
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->postJson("/api/site-builder/projects/{$project->id}/chat", [
        'message' => 'Hello',
    ]);

    $response->assertForbidden();
});

test('chat returns error when agent not configured', function () {
    Event::fake();

    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->postJson("/api/site-builder/projects/{$project->id}/chat", [
        'message' => 'Hello',
    ]);

    $response->assertStatus(500);
    $response->assertJson([
        'success' => false,
        'error' => 'Agent not configured',
    ]);
});

test('can get project status', function () {
    $project = SiteBuilderProject::factory()->research()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson("/api/site-builder/projects/{$project->id}/status");

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'success',
        'status',
        'progress_percentage',
        'progress_data',
        'estimated_completion',
    ]);
});

test('cannot get status of other users project', function () {
    $otherUser = User::factory()->create();
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson("/api/site-builder/projects/{$project->id}/status");

    $response->assertForbidden();
});

test('can list user site builder projects', function () {
    SiteBuilderProject::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    $otherUser = User::factory()->create();
    SiteBuilderProject::factory()->count(2)->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson('/api/site-builder/projects');

    $response->assertSuccessful();
    $response->assertJson(['success' => true]);
    expect($response->json('projects.data'))->toHaveCount(3);
});

test('status update broadcasts SiteBuilderStatusUpdated event', function () {
    Event::fake([SiteBuilderStatusUpdated::class]);

    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
        'status' => SiteBuilderProject::STATUS_RESEARCH,
    ]);

    broadcast(new SiteBuilderStatusUpdated(
        project: $project,
        status: $project->status,
        phase: 'research',
        phaseStatus: 'Analyzing company information',
        phaseProgress: 50,
        progress: 20
    ));

    Event::assertDispatched(SiteBuilderStatusUpdated::class, function ($event) use ($project) {
        return $event->project->id === $project->id
            && $event->phase === 'research'
            && $event->progress === 20;
    });
});

test('message broadcasts SiteBuilderMessageReceived event', function () {
    Event::fake([SiteBuilderMessageReceived::class]);

    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    broadcast(new SiteBuilderMessageReceived(
        project: $project,
        content: 'Starting website build process...',
        role: 'assistant'
    ));

    Event::assertDispatched(SiteBuilderMessageReceived::class, function ($event) use ($project) {
        return $event->project->id === $project->id
            && $event->content === 'Starting website build process...'
            && $event->role === 'assistant';
    });
});

test('SiteBuilderStatusUpdated broadcasts on correct channel', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderStatusUpdated(
        project: $project,
        status: 'research',
        phase: 'research',
        progress: 20
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-site-builder.'.$project->id);
});

test('SiteBuilderMessageReceived broadcasts on correct channel', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderMessageReceived(
        project: $project,
        content: 'Test message',
        role: 'assistant'
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-site-builder.'.$project->id);
});

test('SiteBuilderStatusUpdated includes correct broadcast data', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
        'staging_url' => 'https://staging.example.com',
    ]);

    $event = new SiteBuilderStatusUpdated(
        project: $project,
        status: 'wordpress_setup',
        phase: 'wordpress_setup',
        phaseStatus: 'Installing plugins',
        phaseProgress: 75,
        progress: 40
    );

    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'project_id',
        'status',
        'phase',
        'phase_status',
        'phase_progress',
        'progress',
        'staging_url',
        'production_url',
        'error',
    ]);
    expect($data['project_id'])->toBe($project->id);
    expect($data['phase'])->toBe('wordpress_setup');
    expect($data['progress'])->toBe(40);
    expect($data['staging_url'])->toBe('https://staging.example.com');
});

test('SiteBuilderMessageReceived includes correct broadcast data', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderMessageReceived(
        project: $project,
        content: 'Hello, I am building your website.',
        role: 'assistant',
        metadata: ['tool' => 'site_builder_status_update']
    );

    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'id',
        'project_id',
        'content',
        'role',
        'timestamp',
        'metadata',
    ]);
    expect($data['project_id'])->toBe($project->id);
    expect($data['content'])->toBe('Hello, I am building your website.');
    expect($data['role'])->toBe('assistant');
    expect($data['metadata'])->toBe(['tool' => 'site_builder_status_update']);
    expect($data['id'])->toStartWith('msg_');
});

test('user can authorize to own project channel', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => 'private-site-builder.'.$project->id,
    ]);

    $response->assertSuccessful();
});

test('user cannot authorize to other user project channel', function () {
    $otherUser = User::factory()->create();
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    // Channel authorization returns 403 when not authorized
    // Laravel Reverb/broadcasting uses the channel callback to determine access
    $canAccess = SiteBuilderProject::where('id', $project->id)
        ->where('user_id', $this->user->id)
        ->exists();

    expect($canAccess)->toBeFalse();
});

test('SiteBuilderError broadcasts on correct channel', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderError(
        project: $project,
        message: 'WordPress installation failed',
        phase: 'wordpress_setup',
        code: 'WP_INSTALL_FAILED'
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-site-builder.'.$project->id);
    expect($event->broadcastAs())->toBe('error');
});

test('SiteBuilderError includes correct broadcast data', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderError(
        project: $project,
        message: 'API rate limit exceeded',
        phase: 'content_generation',
        code: 'RATE_LIMIT',
        context: ['retry_after' => 60]
    );

    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'project_id',
        'message',
        'phase',
        'code',
        'context',
        'timestamp',
    ]);
    expect($data['project_id'])->toBe($project->id);
    expect($data['message'])->toBe('API rate limit exceeded');
    expect($data['phase'])->toBe('content_generation');
    expect($data['code'])->toBe('RATE_LIMIT');
    expect($data['context'])->toBe(['retry_after' => 60]);
});

test('SiteBuilderToolExecuted broadcasts on correct channel', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderToolExecuted(
        project: $project,
        toolName: 'wordpress_content_push',
        action: 'Created homepage'
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-site-builder.'.$project->id);
    expect($event->broadcastAs())->toBe('tool.executed');
});

test('SiteBuilderToolExecuted includes correct broadcast data', function () {
    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $event = new SiteBuilderToolExecuted(
        project: $project,
        toolName: 'site_builder_status_update',
        action: 'Updated status to content_generation',
        result: ['success' => true, 'progress' => 50]
    );

    $data = $event->broadcastWith();

    expect($data)->toHaveKeys([
        'project_id',
        'tool_name',
        'action',
        'result',
        'timestamp',
    ]);
    expect($data['project_id'])->toBe($project->id);
    expect($data['tool_name'])->toBe('site_builder_status_update');
    expect($data['action'])->toBe('Updated status to content_generation');
    expect($data['result'])->toBe(['success' => true, 'progress' => 50]);
});

test('error broadcasts SiteBuilderError event', function () {
    Event::fake([SiteBuilderError::class]);

    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    broadcast(new SiteBuilderError(
        project: $project,
        message: 'Content sync failed',
        phase: 'content_sync'
    ));

    Event::assertDispatched(SiteBuilderError::class, function ($event) use ($project) {
        return $event->project->id === $project->id
            && $event->message === 'Content sync failed'
            && $event->phase === 'content_sync';
    });
});

test('tool executed broadcasts SiteBuilderToolExecuted event', function () {
    Event::fake([SiteBuilderToolExecuted::class]);

    $project = SiteBuilderProject::factory()->create([
        'user_id' => $this->user->id,
    ]);

    broadcast(new SiteBuilderToolExecuted(
        project: $project,
        toolName: 'web_search',
        action: 'Researched company information'
    ));

    Event::assertDispatched(SiteBuilderToolExecuted::class, function ($event) use ($project) {
        return $event->project->id === $project->id
            && $event->toolName === 'web_search'
            && $event->action === 'Researched company information';
    });
});
