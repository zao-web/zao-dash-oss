<?php

use App\Jobs\SyncSlackJob;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->workspace = SlackWorkspace::factory()->create([
        'is_active' => true,
        'sync_client_dms' => false,
        'access_token' => 'xoxb-test',
    ]);
});

it('persists a channel whose num_members Slack omitted, without aborting the sync', function () {
    // Slack omits num_members for some conversations (e.g. group DMs). Storing
    // that as null must not violate a NOT NULL constraint — which previously
    // threw mid-sync and froze the whole workspace's last_synced_at.
    $mock = Mockery::mock(SlackService::class);
    $mock->shouldReceive('listChannels')->andReturn([
        [
            'id' => 'G0M0YBD8W',
            'name' => 'zao-leads',
            'is_private' => true,
            'is_archived' => false,
            // no 'num_members' key — mirrors the real Slack payload that broke prod
            'topic' => ['value' => ''],
            'purpose' => ['value' => 'All leads dump'],
        ],
    ]);
    $mock->shouldReceive('getChannelHistoryWithError')->zeroOrMoreTimes()
        ->andReturn(['ok' => true, 'error' => null, 'messages' => []]);
    $mock->shouldReceive('getUserInfo')->zeroOrMoreTimes()->andReturn([
        'name' => 'a', 'real_name' => 'A', 'is_external' => false,
    ]);
    app()->instance(SlackService::class, $mock);

    (new SyncSlackJob(workspaceId: $this->workspace->id))->handle(app(SlackService::class));

    $channel = SlackChannel::where('slack_id', 'G0M0YBD8W')->first();
    expect($channel)->not->toBeNull()
        ->and($channel->member_count)->toBeNull();

    // The sync completed rather than aborting — last_synced_at advanced.
    expect($this->workspace->fresh()->last_synced_at)->not->toBeNull();
});
