<?php

use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\TaxAgencyConnection;
use App\Models\TaxAgencyMfaChallenge;
use App\Models\User;
use App\Services\GitHub\GitHubApiService;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackMentionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.slack.signing_secret' => 'test-signing-secret']);
});

test('handles url verification challenge', function () {
    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'url_verification',
        'challenge' => 'test-challenge-string',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['challenge' => 'test-challenge-string']);
});

test('rejects webhook with invalid signature', function () {
    $payload = json_encode(['type' => 'event_callback']);
    $timestamp = time();

    $response = $this->postJson('/webhooks/slack/events', json_decode($payload, true), [
        'X-Slack-Request-Timestamp' => $timestamp,
        'X-Slack-Signature' => 'invalid-signature',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Invalid signature']);
});

test('accepts webhook with valid signature', function () {
    $payload = json_encode([
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => ['type' => 'unknown_event'],
    ]);
    $timestamp = time();
    $sigBaseString = "v0:{$timestamp}:{$payload}";
    $signature = 'v0='.hash_hmac('sha256', $sigBaseString, config('services.slack.signing_secret'));

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $response = $this->postJson('/webhooks/slack/events', json_decode($payload, true), [
        'X-Slack-Request-Timestamp' => $timestamp,
        'X-Slack-Signature' => $signature,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('rejects webhook with old timestamp', function () {
    $payload = json_encode(['type' => 'event_callback']);
    $timestamp = time() - 400; // 400 seconds ago (> 5 minutes)
    $sigBaseString = "v0:{$timestamp}:{$payload}";
    $signature = 'v0='.hash_hmac('sha256', $sigBaseString, config('services.slack.signing_secret'));

    $response = $this->postJson('/webhooks/slack/events', json_decode($payload, true), [
        'X-Slack-Request-Timestamp' => $timestamp,
        'X-Slack-Signature' => $signature,
    ]);

    $response->assertStatus(401);
});

test('skips signature verification when secret not configured', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => ['type' => 'test'],
    ]);

    $response->assertStatus(200);
});

test('handles message event in monitored channel', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'channel_name' => 'general',
        'monitoring_enabled' => true,
    ]);

    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
        'message_ts' => '1234567890.123456',
    ]);

    $slackApi = $this->mock(SlackApiService::class);
    $slackApi->shouldReceive('storeMessage')
        ->once()
        ->andReturn($message);

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'channel' => 'C12345',
            'ts' => '1234567890.123456',
            'text' => 'Hello world',
            'user' => 'U12345',
        ],
    ]);

    $response->assertStatus(200);
});

test('routes direct message events to conversational bot handling', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'bot_user_id' => 'U12345BOT',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'D12345',
        'channel_name' => 'DM: Justin',
        'is_dm' => true,
        'monitoring_enabled' => true,
    ]);

    $orchestrator = $this->mock(SlackMentionOrchestrator::class);
    $orchestrator->shouldReceive('handleDirectMessage')
        ->once()
        ->withArgs(function ($actualWorkspace, $actualChannel, array $event) use ($workspace, $channel) {
            return $actualWorkspace->is($workspace)
                && $actualChannel->is($channel)
                && ($event['text'] ?? null) === 'what is going on with acme?';
        });

    $this->mock(SlackApiService::class)
        ->shouldNotReceive('storeMessage');

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'channel' => 'D12345',
            'ts' => '1234567890.123456',
            'text' => 'what is going on with acme?',
            'user' => 'U67890',
        ],
    ]);

    $response->assertStatus(200);
});

test('routes pending tax mfa direct messages to challenge capture instead of the conversational bot', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'bot_user_id' => 'U12345BOT',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'D12345',
        'channel_name' => 'DM: Justin',
        'is_dm' => true,
        'monitoring_enabled' => true,
    ]);

    TaxAgencyMfaChallenge::factory()->create([
        'slack_workspace_id' => $workspace->id,
        'slack_channel_id' => 'D12345',
        'slack_user_id' => 'U67890',
        'agency_code' => TaxAgencyConnection::AGENCY_IRS,
        'status' => TaxAgencyMfaChallenge::STATUS_PENDING,
    ]);

    $orchestrator = $this->mock(SlackMentionOrchestrator::class);
    $orchestrator->shouldNotReceive('handleDirectMessage');

    $this->mock(SlackApiService::class)
        ->shouldReceive('postMessage')
        ->once()
        ->withArgs(fn ($actualWorkspace, string $channelId, string $message): bool => $actualWorkspace->is($workspace) && $channelId === 'D12345' && str_contains($message, 'Received the IRS code'))
        ->andReturn(['ok' => true])
        ->shouldNotReceive('storeMessage');

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'channel' => 'D12345',
            'ts' => '1234567890.123456',
            'text' => '482913',
            'user' => 'U67890',
        ],
    ]);

    $response->assertStatus(200);

    $challenge = TaxAgencyMfaChallenge::query()->firstOrFail()->fresh();

    expect($challenge->status)->toBe(TaxAgencyMfaChallenge::STATUS_RESOLVED);
    expect($challenge->response_code)->toBe('482913');
});

test('ignores message events in non-monitored channels', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'monitoring_enabled' => false,
    ]);

    $this->mock(SlackApiService::class)
        ->shouldNotReceive('storeMessage');

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'channel' => 'C12345',
            'ts' => '1234567890.123456',
        ],
    ]);

    $response->assertStatus(200);
});

test('ignores bot messages', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'monitoring_enabled' => true,
    ]);

    $this->mock(SlackApiService::class)
        ->shouldNotReceive('storeMessage');

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'subtype' => 'bot_message',
            'channel' => 'C12345',
            'ts' => '1234567890.123456',
        ],
    ]);

    $response->assertStatus(200);
});

test('allows thread_broadcast messages', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'monitoring_enabled' => true,
    ]);

    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $this->mock(SlackApiService::class)
        ->shouldReceive('storeMessage')
        ->once()
        ->andReturn($message);

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'subtype' => 'thread_broadcast',
            'channel' => 'C12345',
            'ts' => '1234567890.123456',
        ],
    ]);

    $response->assertStatus(200);
});

test('syncs thread for thread replies', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'monitoring_enabled' => true,
    ]);

    $message = SlackMessage::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $slackApi = $this->mock(SlackApiService::class);
    $slackApi->shouldReceive('storeMessage')
        ->once()
        ->andReturn($message);
    $slackApi->shouldReceive('syncThread')
        ->once()
        ->withArgs(function ($actualWorkspace, $actualChannel, $threadTs) use ($workspace, $channel) {
            return $actualWorkspace->is($workspace)
                && $actualChannel->is($channel)
                && $threadTs === '1234567890.000000';
        });

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'message',
            'channel' => 'C12345',
            'ts' => '1234567890.123456',
            'thread_ts' => '1234567890.000000',
        ],
    ]);

    $response->assertStatus(200);
});

test('handles reaction_added event', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'reaction_added',
            'reaction' => 'thumbsup',
            'item' => [
                'type' => 'message',
                'ts' => '1234567890.123456',
            ],
        ],
    ]);

    $response->assertStatus(200);
});

test('handles member_joined_channel event', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'classification' => 'internal',
    ]);

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'member_joined_channel',
            'channel' => 'C12345',
            'user' => 'U12345',
        ],
    ]);

    $response->assertStatus(200);
});

test('ignores events for unknown workspace', function () {
    $this->mock(SlackApiService::class)
        ->shouldNotReceive('storeMessage');

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T99999',
        'event' => [
            'type' => 'message',
            'channel' => 'C12345',
        ],
    ]);

    $response->assertStatus(200);
});

test('handles slash command with invalid signature', function () {
    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'status',
    ], [
        'X-Slack-Request-Timestamp' => time(),
        'X-Slack-Signature' => 'invalid',
    ]);

    $response->assertStatus(401);
});

test('handles zao status command shows system health', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'workspace_name' => 'Test Workspace',
    ]);

    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'monitoring_enabled' => true,
        'client_id' => $client->id,
    ]);

    // Create some test data
    $agent = \App\Models\Agent::factory()->create(['status' => 'active']);
    \App\Models\Project::factory()->create(['status' => 'active']);
    \App\Models\Task::factory()->create(['status' => 'pending']);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'status',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    // Check that the response includes blocks with system information
    $blocks = $response->json('blocks');
    expect($blocks)->not->toBeEmpty();

    // Verify header exists
    expect($blocks[0]['type'])->toBe('header');
    expect($blocks[0]['text']['text'])->toContain('System Status');

    // Verify it includes workspace name
    $responseJson = json_encode($blocks);
    expect($responseJson)->toContain('Test Workspace');

    // Verify it includes metrics sections
    expect($responseJson)->toContain('Agents');
    expect($responseJson)->toContain('Projects & Tasks');
    expect($responseJson)->toContain('This Channel');
});

test('handles zao task command creates real task', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'task Review the proposal',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);
    $response->assertJsonPath('blocks.0.text.text', "✅ *Task created* in project *{$project->name}*");

    // Verify task was created in database
    $this->assertDatabaseHas('tasks', [
        'title' => 'Review the proposal',
        'project_id' => $project->id,
        'status' => 'pending',
        'priority' => 'medium',
        'source' => 'manual',
    ]);
});

test('zao task command fails without active project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => null,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'task Review the proposal',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ No active project found for this channel.

Please link this Slack channel to a project in Zao Dash, or specify a project manually.');
});

test('zao task command fails without description', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'task',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ Please provide a task description: `/zao task <description>`');
});

test('zao task show command returns task detail for linked project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Client Portal',
        'status' => 'active',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $project->update(['slack_channel_id' => $channel->id]);

    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'title' => 'Fix the onboarding bug',
        'priority' => 'high',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "task show {$task->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Task #'.$task->id)
        ->and($json)->toContain('Fix the onboarding bug')
        ->and($json)->toContain('Run Dev Agent');
});

test('zao task start command updates the task status', function () {
    config(['services.slack.signing_secret' => null]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);

    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "task start {$task->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($task->fresh()->status)->toBe('in_progress');
    $this->assertDatabaseHas('task_comments', [
        'task_id' => $task->id,
        'type' => \App\Models\TaskComment::TYPE_STATUS_CHANGE,
        'content' => 'Changed status from pending to in_progress',
    ]);
});

test('zao task priority command updates task priority', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);

    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'priority' => 'low',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "task priority {$task->id} urgent",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($task->fresh()->priority)->toBe('urgent');
});

test('zao task run command assigns and queues the dev agent', function () {
    config(['services.slack.signing_secret' => null]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);

    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);

    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'title' => 'Investigate billing sync drift',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "task run {$task->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();

    expect($task->fresh()->status)->toBe('in_progress')
        ->and($task->fresh()->assigned_to)->toBe($agent->id)
        ->and($task->fresh()->assignee_type)->toBe('agent');

    $this->assertDatabaseHas('agent_tasks', [
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $agentTask = \App\Models\AgentTask::query()->where('task_id', $task->id)->latest()->first();

    expect($agentTask)->not->toBeNull()
        ->and($agentTask->context['slack']['workspace_id'])->toBe('T12345')
        ->and($agentTask->context['slack']['channel_id'])->toBe('C12345');
});

test('zao client show command returns client detail', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme Studio',
        'status' => 'active',
        'website' => 'https://acme.test',
        'health_score' => 88,
    ]);
    \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Refresh',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "client show {$client->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    expect($response->json('blocks.1.text.text'))->toContain('Acme Studio')
        ->and($response->json('blocks.2.fields.2.text'))->toBe("*Website:*\nhttps://acme.test")
        ->and($response->json('blocks.5.text.text'))->toContain('Website Refresh');
});

test('zao client create command creates a client', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'client create Acme Labs website https://acme-labs.test',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $this->assertDatabaseHas('clients', [
        'name' => 'Acme Labs',
        'website' => 'https://acme-labs.test',
        'status' => 'active',
    ]);
});

test('zao client status command updates a client', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'status' => 'prospect',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "client status {$client->id} active",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($client->fresh()->status)->toBe('active');
});

test('zao client list command filters clients by status', function () {
    config(['services.slack.signing_secret' => null]);

    \App\Models\Client::factory()->create([
        'name' => 'Active Client',
        'status' => 'active',
    ]);
    \App\Models\Client::factory()->create([
        'name' => 'Inactive Client',
        'status' => 'inactive',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'client list active',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Active Client')
        ->and($json)->not->toContain('Inactive Client');
});

test('zao project show command returns project detail', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme Studio',
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    \App\Models\Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Ship the first iteration',
        'status' => 'in_progress',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "project show {$project->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    expect($response->json('blocks.1.text.text'))->toContain('Platform Refresh')
        ->and($response->json('blocks.4.elements.0.text'))->toBe('GitHub: `acme/platform`')
        ->and($response->json('blocks.7.text.text'))->toContain('Ship the first iteration');
});

test('zao project create command creates a project', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme Studio',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "project create client {$client->id} Platform Refresh repo acme/platform",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $this->assertDatabaseHas('projects', [
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
        'status' => 'active',
    ]);
});

test('zao project repo command updates a project', function () {
    config(['services.slack.signing_secret' => null]);

    $project = \App\Models\Project::factory()->create([
        'github_repo' => null,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "project repo {$project->id} acme/platform",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($project->fresh()->github_repo)->toBe('acme/platform');
});

test('zao project list command filters projects by client and status', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create();
    $otherClient = \App\Models\Client::factory()->create();

    \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Active Project',
        'status' => 'active',
    ]);
    \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Completed Project',
        'status' => 'completed',
    ]);
    \App\Models\Project::factory()->create([
        'client_id' => $otherClient->id,
        'name' => 'Other Client Project',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "project list client {$client->id} status active",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Active Project')
        ->and($json)->not->toContain('Completed Project')
        ->and($json)->not->toContain('Other Client Project');
});

test('zao lead create command creates a lead', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'lead create Acme Prospect website https://acme-prospect.test email sales@acme.test',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $this->assertDatabaseHas('leads', [
        'company_name' => 'Acme Prospect',
        'website' => 'https://acme-prospect.test',
        'contact_email' => 'sales@acme.test',
        'stage' => 'new',
    ]);
});

test('zao lead stage command updates a lead stage', function () {
    config(['services.slack.signing_secret' => null]);

    $lead = \App\Models\Lead::factory()->create([
        'stage' => 'new',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "lead stage {$lead->id} qualified",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($lead->fresh()->stage)->toBe('qualified');
});

test('zao lead list command filters leads by stage', function () {
    config(['services.slack.signing_secret' => null]);

    \App\Models\Lead::factory()->create([
        'company_name' => 'Qualified Lead',
        'stage' => 'qualified',
    ]);
    \App\Models\Lead::factory()->create([
        'company_name' => 'Lost Lead',
        'stage' => 'lost',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'lead list qualified',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Qualified Lead')
        ->and($json)->not->toContain('Lost Lead');
});

test('zao invoice create command creates a draft invoice', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme Billing',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "invoice create client {$client->id} item Homepage redesign amount 2500 qty 1",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $this->assertDatabaseHas('invoices', [
        'client_id' => $client->id,
        'subject' => 'Homepage redesign',
        'status' => \App\Models\Invoice::STATUS_DRAFT,
    ]);
});

test('zao invoice list command filters invoices by client and status', function () {
    config(['services.slack.signing_secret' => null]);

    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme Billing',
    ]);
    $otherClient = \App\Models\Client::factory()->create();

    \App\Models\Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'draft',
    ]);
    \App\Models\Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'paid',
    ]);
    \App\Models\Invoice::factory()->create([
        'client_id' => $otherClient->id,
        'status' => 'draft',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "invoice list client {$client->id} status draft",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Acme Billing');
});

test('zao website show command returns website project detail', function () {
    config(['services.slack.signing_secret' => null]);

    $project = \App\Models\WebsiteProject::factory()->create([
        'name' => 'Acme Marketing Site',
        'status' => 'building',
        'project_type' => 'autonomous',
        'domain' => 'acme.test',
        'staging_url' => 'https://staging.acme.test',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "website show {$project->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($response->json('blocks.1.text.text'))->toContain('Acme Marketing Site')
        ->and($response->json('blocks.2.fields.0.text'))->toContain('Building')
        ->and($response->json('blocks.3.elements.0.text'))->toContain('https://staging.acme.test');
});

test('zao website create command creates a website project', function () {
    config(['services.slack.signing_secret' => null]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'website create Acme Relaunch type autonomous domain acme.test brief Rebuild the marketing site',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $this->assertDatabaseHas('website_projects', [
        'name' => 'Acme Relaunch',
        'project_type' => 'autonomous',
        'domain' => 'acme.test',
        'status' => 'created',
    ]);
});

test('zao website status command updates a website project', function () {
    config(['services.slack.signing_secret' => null]);

    $project = \App\Models\WebsiteProject::factory()->create([
        'status' => 'created',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "website status {$project->id} building",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    expect($project->fresh()->status)->toBe('building');
});

test('zao website list command filters website projects by status and type', function () {
    config(['services.slack.signing_secret' => null]);

    \App\Models\WebsiteProject::factory()->create([
        'name' => 'Active Autonomous Site',
        'status' => 'building',
        'project_type' => 'autonomous',
    ]);
    \App\Models\WebsiteProject::factory()->create([
        'name' => 'Completed Autonomous Site',
        'status' => 'complete',
        'project_type' => 'autonomous',
    ]);
    \App\Models\WebsiteProject::factory()->create([
        'name' => 'Building Guided Site',
        'status' => 'building',
        'project_type' => 'guided',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'website list status building type autonomous',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();
    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Active Autonomous Site')
        ->and($json)->not->toContain('Completed Autonomous Site')
        ->and($json)->not->toContain('Building Guided Site');
});

test('handles zao log command creates real client note', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $user = \App\Models\User::factory()->create();

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'log Discussed project timeline',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);
    $response->assertJsonPath('blocks.0.text.text', "✅ *Note logged* for client *{$client->name}*");

    // Verify client note was created in database
    $this->assertDatabaseHas('client_notes', [
        'content' => 'Discussed project timeline',
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);
});

test('zao log command fails without client', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => null,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'log Some important note',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ No client found for this channel.

Please link this Slack channel to a client in Zao Dash.');
});

test('zao log command fails without note content', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'log',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ Please provide a note: `/zao log <note>`');
});

test('handles zao command without subcommand', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => '',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');
    expect($text)->toContain('/zao task <description>')
        ->and($text)->toContain('/zao issue <number> [staging|pr] [branch <name>]')
        ->and($text)->toContain('/zao staging')
        ->and($text)->toContain('/zao staging secret [NAME]')
        ->and($text)->toContain('/zao staging publish');
});

test('zao staging command shows staging readiness for the linked project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create(['name' => 'Acme']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'slack_channel_id' => $channel->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
    ]);

    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.acme.test',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => false,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'staging',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $blocksJson = json_encode($response->json('blocks'), JSON_UNESCAPED_SLASHES);
    expect($blocksJson)->toContain('Staging Workflow')
        ->and($blocksJson)->toContain('acme/platform')
        ->and($blocksJson)->toContain('VERCEL_TOKEN')
        ->and($blocksJson)->toContain('Add Secret');
});

test('zao staging secret command opens the secure secret modal', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://slack.com/api/views.open' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'access_token' => 'xoxb-test-token',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
    ]);

    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => false,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'staging secret VERCEL_TOKEN',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
        'trigger_id' => '1337.abc',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('text', 'Opening secure staging secret dialog...');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/views.open'
            && $request['trigger_id'] === '1337.abc'
            && $request['view']['callback_id'] === 'staging_secret_modal'
            && $request['view']['blocks'][1]['element']['initial_value'] === 'VERCEL_TOKEN';
    });
});

test('staging secret modal submission stores the secret and updates the modal privately', function () {
    config(['services.slack.signing_secret' => null]);

    $this->mock(\App\Services\GitHub\GitHubAppService::class)
        ->shouldReceive('getInstallationToken')
        ->andReturn('test-installation-token');

    Http::fake([
        'api.github.com/repos/acme/platform/environments/staging/secrets/public-key' => Http::response([
            'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'key_id' => 'test-key-id',
        ], 200),
        'api.github.com/repos/acme/platform/environments/staging/secrets/*' => Http::response([], 201),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
    ]);

    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => false,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'staging_secret_modal',
            'private_metadata' => json_encode([
                'channel_id' => 'C12345',
            ]),
            'state' => [
                'values' => [
                    'secret_name_block' => [
                        'secret_name' => ['value' => 'VERCEL_TOKEN'],
                    ],
                    'target_scope_block' => [
                        'target_scope' => [
                            'selected_option' => ['value' => 'environment'],
                        ],
                    ],
                    'secret_value_block' => [
                        'secret_value' => ['value' => 'super-secret-value'],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'update');
    $response->assertJsonPath('view.title.text', 'Secret Saved');

    $this->assertDatabaseHas('vault_secrets', [
        'project_id' => $project->id,
        'key' => 'VERCEL_TOKEN',
    ]);

    $this->assertDatabaseHas('vault_secret_github_targets', [
        'github_repo_id' => $repo->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
    ]);
});

test('zao staging publish command queues an approval request', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
        'default_branch' => 'main',
    ]);

    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => false,
    ]);

    $secret = \App\Models\VaultSecret::factory()->create([
        'project_id' => $project->id,
        'client_id' => $project->client_id,
        'key' => 'VERCEL_TOKEN',
        'name' => 'VERCEL_TOKEN',
    ]);

    \App\Models\VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('ready-value'),
        'is_active' => true,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'vault_secret_id' => $secret->id,
        'match_status' => \App\Models\GitHubWorkflowSecretRequirement::MATCH_STATUS_MATCHED,
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    \App\Models\VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
        'drift_status' => \App\Models\VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'staging publish',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $blocksJson = json_encode($response->json('blocks'));
    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();

    expect($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('slack_staging_publish')
        ->and($blocksJson)->toContain('Queued approval')
        ->and($blocksJson)->toContain('Publish to Staging');
});

test('staging publish action in a thread queues an approval with thread context', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'bot_user_id' => 'B12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
        'default_branch' => 'main',
    ]);

    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'staging_url' => 'https://staging.acme.test',
        'onboarding_completed' => true,
    ]);

    $secret = \App\Models\VaultSecret::factory()->create([
        'project_id' => $project->id,
        'client_id' => $project->client_id,
        'key' => 'VERCEL_TOKEN',
        'name' => 'VERCEL_TOKEN',
    ]);

    \App\Models\VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('ready-value'),
        'is_active' => true,
    ]);

    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'vault_secret_id' => $secret->id,
        'match_status' => \App\Models\GitHubWorkflowSecretRequirement::MATCH_STATUS_MATCHED,
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);

    \App\Models\VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
        'drift_status' => \App\Models\VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC,
    ]);

    SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'bot_user_id' => 'B12345',
    ]);

    $payload = [
        'type' => 'block_actions',
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'container' => [
            'thread_ts' => '1234567890.123456',
        ],
        'actions' => [
            [
                'action_id' => 'staging_publish',
                'value' => 'publish-staging',
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $threadContext = SlackThreadContext::query()
        ->where('channel_id', $channel->id)
        ->where('thread_ts', '1234567890.123456')
        ->first();

    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();

    expect($threadContext)->not->toBeNull()
        ->and($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('slack_staging_publish')
        ->and(data_get($approval?->payload, 'slack.thread_context_id'))->toBe($threadContext->id)
        ->and(data_get($approval?->payload, 'publish.repo_full_name'))->toBe('acme/platform');
});

test('zao thread command shows the most relevant thread summary', function () {
    config(['services.slack.signing_secret' => null]);

    User::factory()->create(['role' => 'admin']);
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme',
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'completed',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/acme/platform/pull/17',
            'pr_number' => 17,
            'branch' => 'fix/checkout-flow',
            'staging_url' => 'https://staging.acme.test',
            'deployment_status' => 'deployed',
        ],
    ]);
    SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'current_state' => 'idle',
        'last_interaction_at' => now(),
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'thread',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $responseJson = json_encode($response->json('blocks'));
    expect($responseJson)->toContain('Thread Status')
        ->and($responseJson)->toContain('PR #17')
        ->and($responseJson)->toContain('Issue')
        ->and($responseJson)->toContain('Platform Refresh');
});

test('zao ops command dispatches async control plane work', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'ops summarize this channel',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
        'response_url' => 'https://example.com/slack/response',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment([
        'response_type' => 'ephemeral',
        'text' => 'Working on it...',
    ]);

    Queue::assertPushed(\App\Jobs\ProcessSlackOpsCommandJob::class);
});

test('zao natural language slash command falls back to async ops', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'what needs attention for this client',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
        'response_url' => 'https://example.com/slack/response',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['text' => 'Working on it...']);

    Queue::assertPushed(\App\Jobs\ProcessSlackOpsCommandJob::class);
});

test('zao approvals command lists pending approvals', function () {
    config(['services.slack.signing_secret' => null]);

    SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    \App\Models\ApprovalRequest::factory()->create([
        'description' => 'Approve production deploy for Acme',
        'risk_level' => 'high',
        'status' => 'pending',
    ]);

    \App\Models\ApprovalRequest::factory()->approved()->create([
        'description' => 'Already handled approval',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'approvals',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $responseJson = json_encode($response->json('blocks'));
    expect($responseJson)->toContain('Approve production deploy for Acme')
        ->and($responseJson)->not->toContain('Already handled approval');
});

test('zao integrations command shows linked channel integrations', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create([
        'name' => 'Acme',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
        'classification' => 'client',
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Refresh',
        'slack_channel_id' => $channel->id,
    ]);
    $installation = \App\Models\GitHubInstallation::factory()->create([
        'account_login' => 'acme-org',
    ]);

    \App\Models\GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme-org/website-refresh',
        'name' => 'website-refresh',
    ]);

    \App\Models\PmConnection::create([
        'user_id' => User::factory()->create()->id,
        'client_id' => $client->id,
        'platform' => 'clickup',
        'workspace_name' => 'Acme ClickUp',
        'workspace_id' => 'cu_123',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    \App\Models\HarvestCredential::create([
        'user_id' => User::factory()->create()->id,
        'access_token' => 'harvest-token',
        'refresh_token' => 'refresh-token',
        'account_id' => 'acct_123',
        'account_name' => 'Acme Harvest',
        'is_active' => true,
        'expires_at' => now()->addDay(),
    ]);

    \App\Models\WordPressSite::factory()->create([
        'client_id' => $client->id,
        'name' => 'Acme Marketing Site',
        'url' => 'https://acme.test',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'integrations',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $responseJson = json_encode($response->json('blocks'));
    expect($responseJson)->toContain('Channel Integrations')
        ->and($responseJson)->toContain('website-refresh')
        ->and($responseJson)->toContain('Acme ClickUp')
        ->and($responseJson)->toContain('Acme Marketing Site');
});

test('zao sync github queues repository sync for linked channel', function () {
    config(['services.slack.signing_secret' => null]);
    Queue::fake();

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $installation = \App\Models\GitHubInstallation::factory()->create();
    $repo = \App\Models\GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'sync github',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    Queue::assertPushed(\App\Jobs\SyncGitHubJob::class, function ($job) use ($installation, $repo) {
        return $job->installationId === $installation->id
            && $job->repoId === $repo->id;
    });
});

test('integration sync button queues sync and updates slack response', function () {
    config(['services.slack.signing_secret' => null]);
    Queue::fake();
    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $installation = \App\Models\GitHubInstallation::factory()->create();
    $repo = \App\Models\GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'integration_sync_github',
                'value' => 'sync-github',
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    Queue::assertPushed(\App\Jobs\SyncGitHubJob::class, function ($job) use ($installation, $repo) {
        return $job->installationId === $installation->id
            && $job->repoId === $repo->id;
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true;
    });
});

test('task run agent block action assigns the dev agent and updates the slack response', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'slack_channel_id' => $channel->id,
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'task_run_agent',
                'value' => json_encode([
                    'task_id' => $task->id,
                    'agent_slug' => 'dev-agent',
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    expect($task->fresh()->assigned_to)->toBe($agent->id)
        ->and($task->fresh()->assignee_type)->toBe('agent')
        ->and($task->fresh()->status)->toBe('in_progress');

    $this->assertDatabaseHas('agent_tasks', [
        'task_id' => $task->id,
        'agent_id' => $agent->id,
        'status' => 'pending',
    ]);

    $agentTask = \App\Models\AgentTask::query()->where('task_id', $task->id)->latest()->first();

    expect($agentTask)->not->toBeNull()
        ->and($agentTask->context['slack']['workspace_id'])->toBe('T12345')
        ->and($agentTask->context['slack']['channel_id'])->toBe('C12345');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true;
    });
});

test('sow import confirmation block action provisions the approved import', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'channel_name' => 'client-acme',
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'current_state' => 'awaiting_response',
        'pending_actions' => [],
    ]);

    $pendingActionId = $context->addPendingAction('import_sow', [
        'google_doc_urls' => ['https://docs.google.com/document/d/abc123/edit'],
        'link_to_channel' => true,
        'create_invoices' => true,
    ]);

    $orchestrator = $this->mock(SlackMentionOrchestrator::class);
    $orchestrator->shouldReceive('executeAction')
        ->once()
        ->with(
            Mockery::on(fn (SlackThreadContext $value) => $value->id === $context->id),
            Mockery::on(function (array $action) {
                return $action['type'] === 'import_sow'
                    && $action['google_doc_urls'] === ['https://docs.google.com/document/d/abc123/edit']
                    && $action['link_to_channel'] === true
                    && $action['create_invoices'] === true;
            })
        )
        ->andReturn([
            'success' => true,
            'message' => 'SOW imported and project scaffolded successfully.',
            'parsed' => [
                'client_name' => 'Acme Corp',
                'project_name' => 'Launch Project',
            ],
            'provisioned' => [
                'client_id' => 11,
                'project_id' => 22,
                'summary' => [
                    'contacts_created' => 1,
                    'milestones_created' => 2,
                    'tasks_created' => 8,
                    'invoices_created' => 1,
                ],
                'channel_link' => [
                    'workspace_id' => 'T12345',
                    'channel_id' => 'C12345',
                ],
            ],
        ]);
    $orchestrator->shouldReceive('sendBlockResponse')->once();

    $payload = [
        'type' => 'block_actions',
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'container' => [
            'thread_ts' => '1234567890.123456',
        ],
        'actions' => [
            [
                'action_id' => 'confirm_sow_import',
                'value' => json_encode([
                    'context_id' => $context->id,
                    'pending_action_id' => $pendingActionId,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $context->refresh();
    expect($context->current_state)->toBe('idle')
        ->and($context->pending_actions)->toHaveCount(0)
        ->and($context->completed_actions)->toHaveCount(1)
        ->and($context->completed_actions[0]['type'])->toBe('import_sow')
        ->and($context->completed_actions[0]['result']['success'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true;
    });
});

test('approval approve block action updates the approval request', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $approval = \App\Models\ApprovalRequest::factory()->create([
        'status' => 'pending',
        'description' => 'Approve Acme launch deploy',
        'action_type' => 'deploy_code',
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'approval_approve',
                'value' => (string) $approval->id,
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    expect($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->decision_note)->toBe('Approved from Slack by U12345');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true;
    });
});

test('approval approve block action executes approved staging publish workflows', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $result = [
        'success' => true,
        'repo' => ['full_name' => 'acme/platform', 'id' => 44],
        'deployment' => ['workflow_identifier' => 'deploy.yml', 'publish_branch' => 'main'],
        'publish' => ['workflow_identifier' => 'deploy.yml', 'branch' => 'main'],
    ];

    $this->mock(\App\Services\Slack\SlackEngineeringApprovalService::class)
        ->shouldReceive('executeApprovedStagingPublish')
        ->once()
        ->andReturn($result);

    $this->mock(\App\Services\Slack\SlackStagingThreadService::class)
        ->shouldReceive('rememberPublishedThread')
        ->once()
        ->with(
            \Mockery::on(fn (SlackThreadContext $candidate) => $candidate->id === $threadContext->id),
            $result
        );

    $approval = \App\Models\ApprovalRequest::factory()->create([
        'agent_run_id' => null,
        'status' => 'pending',
        'description' => 'Approve staging publish',
        'action_type' => 'slack_staging_publish',
        'payload' => [
            'slack' => [
                'team_id' => 'T12345',
                'channel_id' => 'C12345',
                'thread_context_id' => $threadContext->id,
            ],
            'publish' => [
                'repo_full_name' => 'acme/platform',
                'workflow_identifier' => 'deploy.yml',
                'branch' => 'main',
            ],
        ],
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'approval_approve',
                'value' => (string) $approval->id,
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    expect($approval->fresh()->status)->toBe('approved');
});

test('thread approval approve action refreshes the thread summary', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
    ]);
    $approval = \App\Models\ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'description' => 'Approve Acme deploy from thread',
        'action_type' => 'deploy_code',
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'approval_approve',
                'value' => json_encode([
                    'approval_id' => $approval->id,
                    'context_id' => $threadContext->id,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    expect($approval->fresh()->status)->toBe('approved');

    Http::assertSent(function ($request) use ($approval) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && $request['text'] === "Approval #{$approval->id} approved."
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('thread interaction response refreshes the thread summary and resumes the agent', function () {
    config(['services.slack.signing_secret' => null]);

    Queue::fake();
    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Compound Engineering',
        'slug' => 'compound-engineering',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => \App\Models\AgentRun::STATUS_AWAITING_INPUT,
        'context' => [
            'slack' => [
                'workspace_id' => 'T12345',
                'channel_id' => 'C12345',
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);
    $threadContext = SlackThreadContext::factory()->awaitingResponse()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
    ]);
    $interaction = \App\Models\InteractionRequest::factory()->confirm()->pending()->create([
        'agent_run_id' => $run->id,
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'interaction_respond_yes',
                'value' => json_encode([
                    'interaction_id' => $interaction->id,
                    'context_id' => $threadContext->id,
                    'response' => 'yes',
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    expect($interaction->fresh()->response)->toBe('yes')
        ->and($interaction->fresh()->responded_via)->toBe('slack')
        ->and($interaction->fresh()->responded_by_id)->toBe($admin->id);

    Queue::assertPushed(\App\Jobs\RunInteractiveAgentJob::class, function ($job) use ($run) {
        return $job->run->is($run)
            && $job->resumeResponse === 'yes';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && $request['text'] === 'Interaction response recorded.'
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('thread retry run action starts a new run and refreshes the thread summary', function () {
    config(['services.slack.signing_secret' => null]);

    Queue::fake();
    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $run = \App\Models\AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'task' => 'Investigate the checkout failure',
        'project_id' => null,
        'task_id' => null,
        'context' => [],
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
        'context_data' => [
            'task_id' => 42,
        ],
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'thread_retry_run',
                'value' => json_encode([
                    'run_id' => $run->id,
                    'context_id' => $threadContext->id,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $newRun = \App\Models\AgentRun::query()->whereKeyNot($run->id)->latest('id')->first();

    expect($newRun)->not->toBeNull()
        ->and($newRun->task)->toBe('Investigate the checkout failure')
        ->and($newRun->invocation_source)->toBe(\App\Models\AgentRun::SOURCE_SLACK)
        ->and($newRun->trigger_metadata['restarted_from_run_id'])->toBe($run->id)
        ->and($newRun->context['slack']['workspace_id'])->toBe('T12345')
        ->and($newRun->context['slack']['channel_id'])->toBe('C12345')
        ->and($newRun->context['slack']['thread_ts'])->toBe('1234567890.123456')
        ->and($newRun->context['slack']['user_id'])->toBe('U12345');

    expect($threadContext->fresh()->agent_run_id)->toBe($newRun->id)
        ->and($threadContext->fresh()->current_state)->toBe('processing')
        ->and($threadContext->fresh()->context_data['agent_run_id'])->toBe($newRun->id);

    Queue::assertPushed(\App\Jobs\RunAgentJob::class, function ($job) use ($newRun) {
        return $job->run->is($newRun);
    });

    Http::assertSent(function ($request) use ($newRun) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && $request['text'] === "Started rerun as Run #{$newRun->id}."
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('cancel agent run action cancels the run and refreshes the thread summary', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => \App\Models\AgentRun::STATUS_RUNNING,
        'task' => 'Investigate the checkout failure',
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => 'T12345',
                'channel_id' => 'C12345',
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'cancel_agent_run',
                'value' => json_encode([
                    'run_id' => $run->id,
                    'context_id' => $threadContext->id,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $run->refresh();

    expect($run->status)->toBe(\App\Models\AgentRun::STATUS_CANCELLED)
        ->and($run->error_message)->toBe('Cancelled from Slack by U12345');

    Http::assertSent(function ($request) use ($run) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && $request['text'] === "Cancelled Run #{$run->id}."
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('thread request review deploy action queues approval and refreshes the thread summary', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'project_id' => null,
        'full_name' => 'owner/repo',
    ]);
    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'production_url' => null,
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
    ]);
    $pullRequest = \App\Models\GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 17,
        'head_branch' => 'feature/fix-42',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'project_id' => null,
        'context' => [
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'pr_number' => 17,
            'pr_url' => $pullRequest->url,
            'branch' => 'feature/fix-42',
        ],
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldNotReceive('dispatchWorkflow');

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'thread_request_review_deploy',
                'value' => json_encode([
                    'run_id' => $run->id,
                    'context_id' => $threadContext->id,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();

    expect($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('slack_review_deploy')
        ->and($run->fresh()->output['deployment_status'] ?? null)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && str_contains((string) $request['text'], 'Queued approval #')
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('thread retry review deploy action queues approval and refreshes the thread summary', function () {
    config(['services.slack.signing_secret' => null]);

    Http::fake([
        'https://example.com/slack/response' => Http::response(['ok' => true], 200),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'project_id' => null,
        'full_name' => 'owner/repo',
    ]);
    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'production_url' => null,
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
    ]);
    $pullRequest = \App\Models\GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 17,
        'head_branch' => 'feature/fix-42',
    ]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'project_id' => null,
        'context' => [
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'pr_number' => 17,
            'pr_url' => $pullRequest->url,
            'branch' => 'feature/fix-42',
            'workflow_run_id' => 8123,
            'deployment_status' => 'failed',
        ],
    ]);
    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldNotReceive('rerunWorkflowRun');

    $payload = [
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'response_url' => 'https://example.com/slack/response',
        'actions' => [
            [
                'action_id' => 'thread_retry_review_deploy',
                'value' => json_encode([
                    'run_id' => $run->id,
                    'context_id' => $threadContext->id,
                ]),
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();

    expect($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('slack_retry_review_deploy')
        ->and($run->fresh()->output['workflow_status'] ?? null)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://example.com/slack/response'
            && ($request['replace_original'] ?? false) === true
            && str_contains((string) $request['text'], 'Queued approval #')
            && str_contains(json_encode($request['blocks']), 'Thread Status');
    });
});

test('zao context command shows channel ops context', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
        'workspace_name' => 'Test Workspace',
    ]);

    $client = \App\Models\Client::factory()->create(['name' => 'Acme']);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'channel_name' => 'acme',
        'client_id' => $client->id,
    ]);

    $project->update(['slack_channel_id' => $channel->id]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'context',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Channel Operations Context');
    expect($json)->toContain('Acme');
    expect($json)->toContain('Platform Refresh');
});

test('zao tasks command lists tasks for linked project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Client Portal',
        'status' => 'active',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $project->update(['slack_channel_id' => $channel->id]);

    \App\Models\Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Finish portal QA',
        'status' => 'in_progress',
        'priority' => 'high',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'tasks in_progress',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Finish portal QA');
    expect($json)->toContain('Client Portal');
});

test('zao tasks command includes lifecycle action buttons', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $project->update(['slack_channel_id' => $channel->id]);

    \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'title' => 'Prepare release notes',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'tasks pending',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertOk();

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('task_mark_in_progress')
        ->and($json)->toContain('task_run_agent')
        ->and($json)->toContain('Run Dev Agent');
});

test('zao link command links current channel to client and project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create(['name' => 'Linked Client']);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Linked Project',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => null,
        'classification' => 'general',
        'monitoring_enabled' => false,
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "link client {$client->id} project {$project->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    expect($channel->fresh()->client_id)->toBe($client->id)
        ->and($channel->fresh()->monitoring_enabled)->toBeTrue()
        ->and($project->fresh()->slack_channel_id)->toBe($channel->id);
});

test('zao find command searches dashboard entities', function () {
    config(['services.slack.signing_secret' => null]);

    \App\Models\Project::factory()->create([
        'name' => 'Authentication Overhaul',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'find authentication',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Authentication Overhaul');
});

test('handles unknown slash command', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/unknown',
        'text' => 'test',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'response_type' => 'ephemeral',
        'text' => 'Unknown command: /unknown',
    ]);
});

test('handles unhandled event types', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/events', [
        'type' => 'event_callback',
        'team_id' => 'T12345',
        'event' => [
            'type' => 'app_home_opened',
        ],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('zao agent command lists agents when no args provided', function () {
    config(['services.slack.signing_secret' => null]);

    $agent1 = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $agent2 = \App\Models\Agent::factory()->create([
        'name' => 'Marketing Agent',
        'slug' => 'marketing-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');
    expect($text)->toContain('dev-agent');
    expect($text)->toContain('marketing-agent');
    expect($text)->toContain('Available Agents');
});

test('zao agent command triggers agent with task description', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent dev-agent Fix the authentication bug',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $text = $response->json('blocks.0.text.text');
    expect($text)->toContain('Dev Agent');
    expect($text)->toContain('Fix the authentication bug');

    // Verify agent run was created
    $this->assertDatabaseHas('agent_runs', [
        'agent_id' => $agent->id,
        'task' => 'Fix the authentication bug',
        'status' => 'running',
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
        'invoked_by' => 'U12345',
    ]);

    // Verify job was dispatched
    Queue::assertPushed(\App\Jobs\RunAgentJob::class, function ($job) use ($agent) {
        return $job->run->agent_id === $agent->id;
    });
});

test('zao agent command triggers agent without task description', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent dev-agent',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    // Verify agent run was created with default task
    $this->assertDatabaseHas('agent_runs', [
        'agent_id' => $agent->id,
        'task' => 'Triggered via Slack /zao command',
        'status' => 'running',
    ]);

    Queue::assertPushed(\App\Jobs\RunAgentJob::class);
});

test('zao agent command dispatches interactive runner for compound engineering', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Compound Engineering',
        'slug' => 'compound-engineering',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent compound-engineering audit deploy workflow',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $run = $agent->runs()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->task)->toBe('audit deploy workflow')
        ->and($run->status)->toBe('running');

    Queue::assertPushed(\App\Jobs\RunInteractiveAgentJob::class, function ($job) use ($run) {
        return $job->run->is($run);
    });

    Queue::assertNotPushed(\App\Jobs\RunAgentJob::class);
});

test('zao issue command queues approval for staging engineering work', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create(['name' => 'Acme']);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/repo',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'project_id' => $project->id,
        'full_name' => 'owner/repo',
        'owner' => 'owner',
        'name' => 'repo',
    ]);
    $issue = \App\Models\GitHubIssue::query()->create([
        'repo_id' => $repo->id,
        'issue_id' => 4242,
        'issue_number' => 42,
        'body' => 'Users cannot log in from Slack-reported flows.',
        'state' => 'open',
        'labels' => [],
        'assignees' => [],
        'task_id' => null,
        'agent_run_id' => null,
        'closed_at' => null,
        'title' => 'Fix the broken login',
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'issue 42 staging',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $run = \App\Models\AgentRun::query()->where('agent_id', $agent->id)->first();
    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();
    expect($run)->not->toBeNull()
        ->and($run->invocation_source)->toBe(\App\Models\AgentRun::SOURCE_SLACK)
        ->and($run->status)->toBe(\App\Models\AgentRun::STATUS_PENDING_APPROVAL)
        ->and($run->context['engineering']['issue_number'])->toBe(42)
        ->and($run->context['engineering']['delivery_target'])->toBe('staging')
        ->and($run->context['project']['github_repo'])->toBe('owner/repo')
        ->and($run->task)->toContain('GitHub Issue: #42 - '.$issue->title)
        ->and($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('agent_execution');

    Queue::assertNotPushed(\App\Jobs\RunAgentJob::class);
});

test('zao issue command queues approval for protected branch execution', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create(['name' => 'Acme']);
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/repo',
    ]);
    SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $repo = \App\Models\GitHubRepo::factory()->create([
        'project_id' => $project->id,
        'full_name' => 'owner/repo',
        'owner' => 'owner',
        'name' => 'repo',
        'default_branch' => 'main',
    ]);
    \App\Models\GitHubIssue::query()->create([
        'repo_id' => $repo->id,
        'issue_id' => 4242,
        'issue_number' => 42,
        'body' => 'Fix the deployment-safe bug.',
        'state' => 'open',
        'labels' => [],
        'assignees' => [],
        'task_id' => null,
        'agent_run_id' => null,
        'closed_at' => null,
        'title' => 'Fix checkout bug',
    ]);

    \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'issue 42 pr branch main',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $run = \App\Models\AgentRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run?->status)->toBe(\App\Models\AgentRun::STATUS_PENDING_APPROVAL)
        ->and(data_get($run?->context, 'engineering.delivery_target'))->toBe('pr')
        ->and(data_get($run?->context, 'engineering.branch_preference'))->toBe('main');

    Queue::assertNotPushed(\App\Jobs\RunAgentJob::class);
});

test('zao issue command uses the channel linked project when a client has multiple active projects', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create(['name' => 'Acme']);
    $linkedProject = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/right-repo',
    ]);
    $otherProject = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/wrong-repo',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $linkedProject->update(['slack_channel_id' => $channel->id]);

    $repo = \App\Models\GitHubRepo::factory()->create([
        'project_id' => $linkedProject->id,
        'full_name' => 'owner/right-repo',
        'owner' => 'owner',
        'name' => 'right-repo',
    ]);
    \App\Models\GitHubRepo::factory()->create([
        'project_id' => $otherProject->id,
        'full_name' => 'owner/wrong-repo',
        'owner' => 'owner',
        'name' => 'wrong-repo',
    ]);

    \App\Models\GitHubIssue::query()->create([
        'repo_id' => $repo->id,
        'issue_id' => 4242,
        'issue_number' => 42,
        'body' => 'Fix the right project.',
        'state' => 'open',
        'labels' => [],
        'assignees' => [],
        'task_id' => null,
        'agent_run_id' => null,
        'closed_at' => null,
        'title' => 'Fix the correct issue',
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'issue 42 pr',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);

    $run = \App\Models\AgentRun::query()->where('agent_id', $agent->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->project_id)->toBe($linkedProject->id)
        ->and($run->context['project']['github_repo'])->toBe('owner/right-repo');
});

test('zao issue command fails when channel client has multiple active projects and no explicit linked project', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    \App\Models\Project::factory()->count(2)->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'issue 42 pr',
        'user_id' => 'U12345',
        'channel_id' => $channel->channel_id,
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    expect((string) $response->json('text'))->toContain('multiple active projects');
    Queue::assertNothingPushed();
});

test('zao runs command lists recent project agent runs', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);

    \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'running',
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
        'task' => 'Fix login bug',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'delivery_target' => 'staging',
                'repo' => 'owner/repo',
            ],
        ],
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'runs active',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Active Runs')
        ->and($json)->toContain('Platform Refresh')
        ->and($json)->toContain('Fix login bug');
});

test('zao run show command returns run detail for linked project', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = \App\Models\Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'completed',
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'delivery_target' => 'pr',
                'repo' => 'owner/repo',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/owner/repo/pull/17',
            'pr_number' => 17,
        ],
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "run show {$run->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $json = json_encode($response->json('blocks'));
    expect($json)->toContain('Agent Run')
        ->and($json)->toContain('Run #'.$run->id)
        ->and($json)->toContain('Open PR');
});

test('zao run retry command restarts the selected project run from slack', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'task' => 'Investigate login bug',
        'context' => [
            'engineering' => [
                'repo' => 'owner/repo',
            ],
        ],
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "run retry {$run->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $newRun = \App\Models\AgentRun::query()->whereKeyNot($run->id)->latest('id')->first();

    expect($newRun)->not->toBeNull()
        ->and($newRun->project_id)->toBe($project->id)
        ->and($newRun->trigger_metadata['restarted_from_run_id'])->toBe($run->id)
        ->and($newRun->context['slack']['workspace_id'])->toBe('T12345')
        ->and($newRun->context['slack']['channel_id'])->toBe('C12345')
        ->and($newRun->context['slack']['user_id'])->toBe('U12345');

    Queue::assertPushed(\App\Jobs\RunAgentJob::class, function ($job) use ($newRun) {
        return $job->run->is($newRun);
    });
});

test('zao run cancel command cancels the selected project run from slack', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => \App\Models\AgentRun::STATUS_RUNNING,
        'task' => 'Investigate login bug',
        'invocation_source' => \App\Models\AgentRun::SOURCE_SLACK,
        'context' => [
            'engineering' => [
                'repo' => 'owner/repo',
            ],
        ],
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => "run cancel {$run->id}",
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'in_channel']);

    $run->refresh();
    $json = json_encode($response->json('blocks'));

    expect($run->status)->toBe(\App\Models\AgentRun::STATUS_CANCELLED)
        ->and($run->error_message)->toBe('Cancelled from Slack by U12345')
        ->and($json)->toContain('Cancelled Run #'.$run->id);
});

test('zao agent command includes project context when channel linked', function () {
    Queue::fake();
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/repo',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);

    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent dev-agent Review the code',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);

    // Verify agent run includes project context
    $run = \App\Models\AgentRun::where('agent_id', $agent->id)->first();
    expect($run)->not->toBeNull();
    expect($run->context)->toHaveKey('project');
    expect($run->context['project']['id'])->toBe($project->id);
    expect($run->context['project']['github_repo'])->toBe('owner/repo');
    expect($run->project_id)->toBe($project->id);
});

test('zao agent command fails for disabled agent', function () {
    config(['services.slack.signing_secret' => null]);

    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'dev-agent',
        'status' => 'disabled',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent dev-agent Fix the bug',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');
    expect($text)->toContain('not found or not active');

    // Verify no agent run was created
    $this->assertDatabaseMissing('agent_runs', [
        'agent_id' => $agent->id,
    ]);
});

test('zao agent command fails for circuit broken agent', function () {
    config(['services.slack.signing_secret' => null]);

    $agent = \App\Models\Agent::factory()->create([
        'slug' => 'dev-agent',
        'status' => 'active',
        'circuit_broken_at' => now(),
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent dev-agent Fix the bug',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');
    expect($text)->toContain('currently unavailable');

    // Verify no agent run was created
    $this->assertDatabaseMissing('agent_runs', [
        'agent_id' => $agent->id,
    ]);
});

test('zao agent command fails for unknown agent', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent nonexistent-agent Fix the bug',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    $text = $response->json('text');
    expect($text)->toContain('not found or not active');
});

test('zao agent command shows no agents when none active', function () {
    config(['services.slack.signing_secret' => null]);

    // Create only disabled agents
    \App\Models\Agent::factory()->create(['status' => 'disabled']);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'agent',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ No active agents found.');
});

test('zao status command fails without workspace', function () {
    config(['services.slack.signing_secret' => null]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'status',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T99999',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertJsonPath('text', '❌ Workspace not found. Please reconnect Slack integration.');
});

test('zao status command shows health indicators', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    // Create failed jobs to trigger warning state
    \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
        'uuid' => \Illuminate\Support\Str::uuid(),
        'connection' => 'sync',
        'queue' => 'default',
        'payload' => json_encode(['test' => 'data']),
        'exception' => 'Test exception',
        'failed_at' => now(),
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'status',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);

    $blocks = $response->json('blocks');
    $headerText = $blocks[0]['text']['text'];

    // Should show warning emoji when there are failed jobs
    expect($headerText)->toContain('🟡');
    expect($headerText)->toContain('System Status');
});

test('zao focus command shows current priorities', function () {
    config(['services.slack.signing_secret' => null]);

    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => \App\Models\AgentRun::STATUS_PENDING_APPROVAL,
    ]);
    \App\Models\ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
        'description' => 'Approve Acme engineering run',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'focus',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertSee('What To Work On Today');
    $response->assertSee('Approve Acme engineering run');
});

test('natural language slash command can return focus briefing', function () {
    config(['services.slack.signing_secret' => null]);

    $agent = \App\Models\Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => \App\Models\AgentRun::STATUS_PENDING_APPROVAL,
    ]);
    \App\Models\ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
        'description' => 'Approve launch workflow',
    ]);

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'what should I work on today?',
        'user_id' => 'U12345',
        'channel_id' => 'C12345',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);
    $response->assertSee('What To Work On Today');
    $response->assertSee('Approve launch workflow');
});

test('zao status command works without channel context', function () {
    config(['services.slack.signing_secret' => null]);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    // No channel created - should still work

    $response = $this->postJson('/webhooks/slack/slash', [
        'command' => '/zao',
        'text' => 'status',
        'user_id' => 'U12345',
        'channel_id' => 'C99999',
        'team_id' => 'T12345',
    ]);

    $response->assertStatus(200);
    $response->assertJsonFragment(['response_type' => 'ephemeral']);

    // Should not include "This Channel" section
    $blocks = $response->json('blocks');
    $responseJson = json_encode($blocks);
    expect($responseJson)->not->toContain('This Channel');
});
