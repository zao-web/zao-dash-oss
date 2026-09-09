<?php

use App\Models\Client;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackChannelMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeChannel(array $attributes = []): SlackChannel
{
    return SlackChannel::factory()->create(array_merge([
        'classification' => 'general',
        'is_monitored' => false,
    ], $attributes));
}

beforeEach(function () {
    // A single shared workspace keeps factory-created channels in one place.
    $this->workspace = SlackWorkspace::factory()->create();
});

it('auto-links a channel whose name exactly matches a client', function () {
    $client = Client::factory()->create(['name' => 'Acme Corporation']);
    $channel = makeChannel([
        'workspace_id' => $this->workspace->id,
        'name' => 'acme-corporation',
        'channel_name' => 'acme-corporation',
    ]);

    $result = app(SlackChannelMatcherService::class)->matchChannel($channel);

    expect($result['client_id'])->toBe($client->id)
        ->and($result['confidence'])->toBeGreaterThanOrEqual(70);
});

it('auto-links a channel sharing a root token with a suffixed client name', function () {
    // Regression: "example-client" vs "Example Client LLC" scored 0 because the
    // slugs share no whole token and neither contains the other. Stripping
    // the corporate suffix surfaces the shared root "locum".
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = makeChannel([
        'workspace_id' => $this->workspace->id,
        'name' => 'example-client',
        'channel_name' => 'example-client',
    ]);

    $result = app(SlackChannelMatcherService::class)->matchChannel($channel);

    expect($result['client_id'])->toBe($client->id)
        ->and($result['confidence'])->toBeGreaterThanOrEqual(70);
});

it('does not auto-link a channel sharing only a generic stopword', function () {
    // Guard against suffix-stripping over-matching: "media-updates" must not
    // link to "Example Client LLC" — they share only the stopword "media".
    Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = makeChannel([
        'workspace_id' => $this->workspace->id,
        'name' => 'media-updates',
        'channel_name' => 'media-updates',
    ]);

    $result = app(SlackChannelMatcherService::class)->matchChannel($channel);

    expect($result['client_id'])->toBeNull();
});

it('enables monitoring when auto-linking a channel', function () {
    // Linking a client without flipping is_monitored leaves the channel's
    // messages un-ingested by SyncSlackJob — invisible on retainer reports.
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $channel = makeChannel([
        'workspace_id' => $this->workspace->id,
        'name' => 'example-client',
        'channel_name' => 'example-client',
        'classification' => 'general',
        'is_monitored' => false,
        'monitoring_enabled' => false,
    ]);

    app(SlackChannelMatcherService::class)->autoLinkAllChannels();

    $channel->refresh();
    expect($channel->client_id)->toBe($client->id)
        ->and($channel->is_monitored)->toBeTrue()
        ->and($channel->monitoring_enabled)->toBeTrue()
        ->and($channel->classification)->toBe('client');
});
