<?php

use App\Jobs\SyncSlackJob;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncSlackJob::dispatch();

    Queue::assertPushed(SyncSlackJob::class);
});

test('job can be dispatched with parameters', function () {
    Queue::fake();

    SyncSlackJob::dispatch(workspaceId: 1, channelId: 2, messageLimit: 50);

    Queue::assertPushed(SyncSlackJob::class, function ($job) {
        return $job->workspaceId === 1
            && $job->channelId === 2
            && $job->messageLimit === 50;
    });
});

test('handle syncs all active workspaces', function () {
    $workspace1 = SlackWorkspace::factory()->create(['is_active' => true]);
    $workspace2 = SlackWorkspace::factory()->create(['is_active' => true]);
    $inactiveWorkspace = SlackWorkspace::factory()->create(['is_active' => false]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->twice()->andReturn([]);

    $job = new SyncSlackJob;
    $job->handle($slackService);
});

test('handle syncs specific workspace when workspaceId provided', function () {
    $targetWorkspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $otherWorkspace = SlackWorkspace::factory()->create(['is_active' => true]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')
        ->once()
        ->with(Mockery::on(fn ($ws) => $ws->id === $targetWorkspace->id))
        ->andReturn([]);

    $job = new SyncSlackJob(workspaceId: $targetWorkspace->id);
    $job->handle($slackService);
});

test('handle syncs channels for workspace', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);

    $channels = [
        [
            'id' => 'C123',
            'name' => 'general',
            'is_private' => false,
            'is_archived' => false,
            'num_members' => 10,
            'topic' => ['value' => 'General chat'],
            'purpose' => ['value' => 'Team discussion'],
        ],
    ];

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->once()->andReturn($channels);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);

    expect(SlackChannel::count())->toBe(1);

    $channel = SlackChannel::first();
    expect($channel->slack_id)->toBe('C123')
        ->and($channel->name)->toBe('general')
        ->and($channel->is_private)->toBeFalse()
        ->and($channel->member_count)->toBe(10);
});

test('handle updates existing channels', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $existingChannel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'slack_id' => 'C123',
        'name' => 'old-name',
    ]);

    $channels = [
        ['id' => 'C123', 'name' => 'new-name', 'is_private' => false, 'is_archived' => false],
    ];

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn($channels);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);

    $existingChannel->refresh();
    expect($existingChannel->name)->toBe('new-name');
});

test('handle syncs messages for monitored channels', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $monitoredChannel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => true,
    ]);
    $unmonitoredChannel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => false,
    ]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn([]);
    $slackService->shouldReceive('getChannelHistory')
        ->once()
        ->andReturn([]);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);
});

test('handle syncs specific channel when channelId provided', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $targetChannel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => true,
    ]);
    $otherChannel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => true,
    ]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn([]);
    $slackService->shouldReceive('getChannelHistory')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::on(fn ($id) => $id === $targetChannel->slack_id),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([]);

    $job = new SyncSlackJob(workspaceId: $workspace->id, channelId: $targetChannel->id);
    $job->handle($slackService);
});

test('handle stores messages in database', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => true,
    ]);

    $messages = [
        [
            'ts' => '1234567890.123456',
            'user' => 'U123',
            'text' => 'Hello world',
            'type' => 'message',
        ],
    ];

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn([]);
    $slackService->shouldReceive('getChannelHistory')->andReturn($messages);
    $slackService->shouldReceive('getUserInfo')->andReturn(['name' => 'John']);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);

    expect(SlackMessage::count())->toBe(1);

    $message = SlackMessage::first();
    expect($message->text)->toBe('Hello world');
});

test('handle skips bot messages when configured', function () {
    $workspace = SlackWorkspace::factory()->create(['is_active' => true]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_monitored' => true,
        'sync_bot_messages' => false,
    ]);

    $messages = [
        ['ts' => '1.1', 'bot_id' => 'B123', 'text' => 'Bot message'],
        ['ts' => '1.2', 'user' => 'U123', 'text' => 'User message'],
    ];

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn([]);
    $slackService->shouldReceive('getChannelHistory')->andReturn($messages);
    $slackService->shouldReceive('getUserInfo')->andReturn(['name' => 'User']);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);

    expect(SlackMessage::count())->toBe(1);
    expect(SlackMessage::first()->text)->toBe('User message');
});

test('handle updates workspace last_synced_at', function () {
    $workspace = SlackWorkspace::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')->andReturn([]);

    $job = new SyncSlackJob(workspaceId: $workspace->id);
    $job->handle($slackService);

    $workspace->refresh();
    expect($workspace->last_synced_at)->not->toBeNull();
});

test('handle logs errors and continues', function () {
    Log::spy();

    $workspace1 = SlackWorkspace::factory()->create(['is_active' => true]);
    $workspace2 = SlackWorkspace::factory()->create(['is_active' => true]);

    $slackService = Mockery::mock(SlackService::class);
    $slackService->shouldReceive('listChannels')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });

    $job = new SyncSlackJob;
    $job->handle($slackService);

    Log::shouldHaveReceived('error')
        ->with('Slack sync failed for workspace', Mockery::any());
});

test('default message limit is 100', function () {
    $job = new SyncSlackJob;
    expect($job->messageLimit)->toBe(100);
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncSlackJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncSlackJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
