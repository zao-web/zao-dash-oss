<?php

use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->oauthService = Mockery::mock(SlackOAuthService::class);
    $this->apiService = Mockery::mock(SlackApiService::class);

    $this->app->instance(SlackOAuthService::class, $this->oauthService);
    $this->app->instance(SlackApiService::class, $this->apiService);
});

// Status tests
test('status returns empty when no workspaces', function () {
    $response = $this->getJson(route('slack.status'));

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => false,
        'workspaces' => [],
    ]);
});

test('status returns workspaces with monitored channels', function () {
    $workspace = SlackWorkspace::factory()
        ->has(SlackChannel::factory()->count(3)->state(['monitoring_enabled' => true]))
        ->create(['is_primary' => true]);

    $response = $this->getJson(route('slack.status'));

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
    ]);
    $response->assertJsonCount(1, 'workspaces');
    $response->assertJsonPath('workspaces.0.channels_count', 3);
    $response->assertJsonPath('workspaces.0.is_primary', true);
});

// OAuth redirect tests
test('redirect generates slack oauth url with state', function () {
    $authUrl = 'https://slack.com/oauth/v2/authorize?client_id=123';

    $this->oauthService->shouldReceive('getAuthUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn($authUrl);

    $response = $this->get(route('slack.redirect'));

    $response->assertRedirect($authUrl);
    expect(session('slack_oauth_state'))->not->toBeNull();
});

// OAuth callback tests
test('callback validates state parameter', function () {
    session(['slack_oauth_state' => 'valid-state']);

    $response = $this->get(route('slack.callback', [
        'state' => 'invalid-state',
        'code' => 'auth-code',
    ]));

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error', 'Invalid OAuth state. Please try again.');
});

test('callback handles oauth error', function () {
    session(['slack_oauth_state' => 'valid-state']);

    $response = $this->get(route('slack.callback', [
        'state' => 'valid-state',
        'error' => 'access_denied',
    ]));

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('access_denied');
});

test('callback exchanges code for tokens and stores workspace', function () {
    $state = 'valid-state';
    session(['slack_oauth_state' => $state]);

    $tokenData = [
        'access_token' => 'xoxb-slack-token',
        'team' => [
            'id' => 'T12345',
            'name' => 'Test Workspace',
        ],
    ];

    $workspace = SlackWorkspace::factory()->make([
        'workspace_id' => 'T12345',
        'workspace_name' => 'Test Workspace',
    ]);

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->with('auth-code')
        ->andReturn($tokenData);

    $this->oauthService->shouldReceive('storeWorkspace')
        ->once()
        ->with($tokenData)
        ->andReturn($workspace);

    $this->apiService->shouldReceive('syncChannels')
        ->once()
        ->with(Mockery::on(fn ($w) => $w->workspace_id === 'T12345'));

    $response = $this->get(route('slack.callback', [
        'state' => $state,
        'code' => 'auth-code',
    ]));

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
    expect(session('slack_oauth_state'))->toBeNull();
});

test('callback handles token exchange failure', function () {
    $state = 'valid-state';
    session(['slack_oauth_state' => $state]);

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->andThrow(new Exception('Invalid code'));

    $response = $this->get(route('slack.callback', [
        'state' => $state,
        'code' => 'invalid-code',
    ]));

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
});

// Disconnect tests
test('disconnect revokes access and deletes workspace', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_name' => 'Test Workspace',
    ]);

    $this->oauthService->shouldReceive('revokeAccess')
        ->once()
        ->with(Mockery::on(fn ($w) => $w->id === $workspace->id));

    $response = $this->delete(route('slack.disconnect', $workspace));

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
    expect(session('success'))->toContain('Test Workspace');
});

// Sync channels tests
test('sync channels fetches and stores channels', function () {
    $workspace = SlackWorkspace::factory()->create();

    $this->apiService->shouldReceive('syncChannels')
        ->once()
        ->with(Mockery::on(fn ($w) => $w->id === $workspace->id))
        ->andReturn(5);

    $response = $this->postJson(route('slack.syncChannels', $workspace));

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'synced' => 5,
    ]);
});

test('sync channels handles api errors', function () {
    $workspace = SlackWorkspace::factory()->create();

    $this->apiService->shouldReceive('syncChannels')
        ->once()
        ->andThrow(new Exception('Slack API error'));

    $response = $this->postJson(route('slack.syncChannels', $workspace));

    $response->assertStatus(500);
    $response->assertJsonPath('error', 'Slack API error');
});

// List channels tests
test('list channels returns workspace channels', function () {
    $workspace = SlackWorkspace::factory()
        ->has(SlackChannel::factory()->count(3))
        ->create();

    $response = $this->getJson(route('slack.listChannels', $workspace));

    $response->assertStatus(200);
    $response->assertJsonCount(3, 'channels');
    $response->assertJsonStructure([
        'channels' => [
            '*' => [
                'id',
                'channel_id',
                'name',
                'is_private',
                'classification',
                'monitoring_enabled',
                'messages_count',
            ],
        ],
    ]);
});

// Update channel tests
test('update channel updates classification', function () {
    $channel = SlackChannel::factory()->create([
        'classification' => 'general',
    ]);

    $response = $this->putJson(route('slack.updateChannel', $channel), [
        'classification' => 'client',
    ]);

    $response->assertStatus(200);
    expect($channel->fresh()->classification)->toBe('client');
});

test('update channel validates classification', function () {
    $channel = SlackChannel::factory()->create();

    $response = $this->putJson(route('slack.updateChannel', $channel), [
        'classification' => 'invalid',
    ]);

    $response->assertStatus(422);
});

test('update channel updates monitoring enabled', function () {
    $channel = SlackChannel::factory()->create([
        'monitoring_enabled' => false,
    ]);

    $response = $this->putJson(route('slack.updateChannel', $channel), [
        'monitoring_enabled' => true,
    ]);

    $response->assertStatus(200);
    expect($channel->fresh()->monitoring_enabled)->toBeTrue();
});

// Sync messages tests
test('sync messages fetches and stores channel history', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->for($workspace)->create();

    $history = [
        'messages' => [
            ['ts' => '1234.5678', 'text' => 'Message 1', 'user' => 'U123'],
            ['ts' => '1234.5679', 'text' => 'Message 2', 'user' => 'U456'],
        ],
    ];

    $this->apiService->shouldReceive('getChannelHistory')
        ->once()
        ->andReturn($history);

    $this->apiService->shouldReceive('storeMessage')
        ->times(2);

    $response = $this->postJson(route('slack.syncMessages', $channel));

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 2);
    expect($channel->fresh()->last_synced_at)->not->toBeNull();
});

test('sync messages skips bot and system messages', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->for($workspace)->create();

    $history = [
        'messages' => [
            ['ts' => '1', 'text' => 'Regular message', 'user' => 'U123'],
            ['ts' => '2', 'text' => 'Bot message', 'subtype' => 'bot_message'],
            ['ts' => '3', 'text' => 'Channel join', 'subtype' => 'channel_join'],
            ['ts' => '4', 'text' => 'Thread broadcast', 'subtype' => 'thread_broadcast', 'user' => 'U123'],
        ],
    ];

    $this->apiService->shouldReceive('getChannelHistory')
        ->once()
        ->andReturn($history);

    $this->apiService->shouldReceive('storeMessage')
        ->times(2); // Only regular message and thread_broadcast

    $response = $this->postJson(route('slack.syncMessages', $channel));

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 2);
});

test('sync messages handles api errors', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->for($workspace)->create();

    $this->apiService->shouldReceive('getChannelHistory')
        ->once()
        ->andThrow(new Exception('Slack API error'));

    $response = $this->postJson(route('slack.syncMessages', $channel));

    $response->assertStatus(500);
});

// Recent messages tests
test('recent messages returns latest channel messages', function () {
    $channel = SlackChannel::factory()
        ->has(SlackMessage::factory()->count(60))
        ->create();

    $response = $this->getJson(route('slack.recentMessages', $channel));

    $response->assertStatus(200);
    $response->assertJsonCount(50, 'messages'); // Limited to 50
    $response->assertJsonStructure([
        'messages' => [
            '*' => [
                'id',
                'user_name',
                'content',
                'has_action_item',
                'is_thread_reply',
                'created_at',
            ],
        ],
    ]);
});

// Authentication tests
test('slack integration requires authentication', function () {
    auth()->logout();

    $response = $this->getJson(route('slack.status'));

    $response->assertStatus(401);
});
