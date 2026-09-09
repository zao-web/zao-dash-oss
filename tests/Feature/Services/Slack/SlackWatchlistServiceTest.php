<?php

use App\Models\SlackChannel;
use App\Models\SlackUserWatchlistItem;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackWatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SlackWatchlistService::class);
});

test('tracks and untracks channels by query', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_name' => 'acme-client',
        'name' => 'acme-client',
        'monitoring_enabled' => true,
    ]);

    $track = $this->service->trackByQuery($workspace, 'U12345', 'acme-client');

    expect($track['success'])->toBeTrue()
        ->and($track['items'])->toHaveCount(1);

    $this->assertDatabaseHas('slack_user_watchlist_items', [
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
        'is_active' => true,
    ]);

    $items = $this->service->listItems($workspace, 'U12345');
    expect($items)->toHaveCount(1);

    $untrack = $this->service->untrackByQuery($workspace, 'U12345', 'acme-client');

    expect($untrack['success'])->toBeTrue()
        ->and($untrack['items'])->toHaveCount(1);

    $this->assertDatabaseHas('slack_user_watchlist_items', [
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
        'is_active' => false,
    ]);
});

test('clears a watchlist', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_name' => 'beta',
        'monitoring_enabled' => true,
    ]);

    SlackUserWatchlistItem::factory()->create([
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
    ]);

    $result = $this->service->clear($workspace, 'U12345');

    expect($result['success'])->toBeTrue()
        ->and($result['items'])->toHaveCount(1);

    $this->assertDatabaseHas('slack_user_watchlist_items', [
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
        'is_active' => false,
    ]);
});
