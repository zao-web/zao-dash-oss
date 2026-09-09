<?php

use App\Jobs\SyncSlackJob;
use App\Models\Client;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake the bus so SyncSlackJob is recorded rather than executed — the
    // command's responsibility is to resolve/link the channel and dispatch
    // the sync with the right arguments; the sync pipeline is tested elsewhere.
    Bus::fake();

    // With the job faked, the command's message delta is always 0, so its
    // zero-result diagnostic always runs. Bind a benign SlackService so that
    // probe doesn't make a real network call; individual tests override this
    // when they want to assert on a specific Slack response.
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('getChannelHistoryWithError')->zeroOrMoreTimes()
        ->andReturn(['ok' => true, 'error' => null, 'messages' => []]);
    app()->instance(SlackService::class, $slack);

    $this->workspace = SlackWorkspace::factory()->create();
});

it('dispatches a sync for a resolved channel with the parsed since window', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'example-client',
        'channel_name' => 'example-client',
        'classification' => 'client',
        'client_id' => $client->id,
        'is_monitored' => true,
    ]);

    $this->artisan('slack:backfill-channel', ['channel' => (string) $channel->id, '--since' => '2026-05-01'])
        ->assertSuccessful();

    Bus::assertDispatched(SyncSlackJob::class, function (SyncSlackJob $job) use ($channel) {
        return $job->channelId === $channel->id
            && $job->workspaceId === $channel->workspace_id
            && $job->sinceTimestamp === \Carbon\Carbon::parse('2026-05-01')->timestamp;
    });
});

it('resolves a channel by its Slack id, not just the numeric pk', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'channel_id' => 'C00EXAMPLE02',
        'slack_id' => 'C00EXAMPLE02',
        'name' => 'example-client',
        'channel_name' => 'example-client',
        'classification' => 'client',
        'client_id' => $client->id,
        'is_monitored' => true,
    ]);

    $this->artisan('slack:backfill-channel', ['channel' => 'C00EXAMPLE02', '--since' => '30 days'])
        ->assertSuccessful();

    Bus::assertDispatched(SyncSlackJob::class, fn (SyncSlackJob $job) => $job->channelId === $channel->id);
});

it('links a client and enables monitoring before dispatching when --client is passed', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'example-client',
        'channel_name' => 'example-client',
        'classification' => 'general',
        'client_id' => null,
        'is_monitored' => false,
        'monitoring_enabled' => false,
    ]);

    $this->artisan('slack:backfill-channel', [
        'channel' => (string) $channel->id,
        '--client' => (string) $client->id,
    ])->assertSuccessful();

    $channel->refresh();
    expect($channel->client_id)->toBe($client->id)
        ->and($channel->is_monitored)->toBeTrue()
        ->and($channel->monitoring_enabled)->toBeTrue()
        ->and($channel->classification)->toBe('client');

    Bus::assertDispatched(SyncSlackJob::class);
});

it('interprets a relative --since phrase as time ago', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'example-client',
        'channel_name' => 'example-client',
        'classification' => 'client',
        'client_id' => $client->id,
        'is_monitored' => true,
    ]);

    $this->artisan('slack:backfill-channel', ['channel' => (string) $channel->id, '--since' => '60 days'])
        ->assertSuccessful();

    Bus::assertDispatched(SyncSlackJob::class, function (SyncSlackJob $job) {
        // ~60 days ago, allowing a couple minutes of test-execution drift.
        $expected = now()->subDays(60)->timestamp;

        return abs($job->sinceTimestamp - $expected) < 120;
    });
});

it('fails cleanly when the channel cannot be resolved', function () {
    $this->artisan('slack:backfill-channel', ['channel' => 'C_DOES_NOT_EXIST'])
        ->assertFailed();

    Bus::assertNotDispatched(SyncSlackJob::class);
});

it('surfaces the real Slack error when a backfill returns no messages', function () {
    // When nothing syncs, the command probes Slack directly and reports the
    // actual API error instead of a misleading bare "0 → 0".
    $slack = Mockery::mock(SlackService::class);
    $slack->shouldReceive('getChannelHistoryWithError')->zeroOrMoreTimes()
        ->andReturn(['ok' => false, 'error' => 'not_in_channel', 'messages' => []]);
    app()->instance(SlackService::class, $slack);

    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $this->workspace->id,
        'channel_id' => 'C00EXAMPLE01',
        'slack_id' => 'C00EXAMPLE01',
        'name' => 'Example group DM',
        'channel_name' => 'Example group DM',
        'classification' => 'client',
        'client_id' => $client->id,
        'is_monitored' => true,
    ]);

    $this->artisan('slack:backfill-channel', ['channel' => (string) $channel->id, '--since' => '2026-05-01'])
        ->expectsOutputToContain('not_in_channel')
        ->assertSuccessful();
});
