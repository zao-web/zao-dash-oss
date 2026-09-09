<?php

use App\Models\Client;
use App\Models\SlackChannel;

test('has guarded attributes empty', function () {
    expect((new SlackChannel)->getGuarded())->toBe(['*']);
});

test('casts is_private to boolean', function () {
    $channel = SlackChannel::factory()->create(['is_private' => true]);

    expect($channel->is_private)->toBeTrue();
});

test('casts is_shared to boolean', function () {
    $channel = SlackChannel::factory()->create(['is_shared' => false]);

    expect($channel->is_shared)->toBeFalse();
});

test('casts monitoring_enabled to boolean', function () {
    $channel = SlackChannel::factory()->create(['monitoring_enabled' => true]);

    expect($channel->monitoring_enabled)->toBeTrue();
});

test('casts last_synced_at to datetime', function () {
    $channel = SlackChannel::factory()->create(['last_synced_at' => now()]);

    expect($channel->last_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to workspace relationship', function () {
    $channel = SlackChannel::factory()->create();

    expect($channel->workspace())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $channel = SlackChannel::factory()->create();

    expect($channel->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many messages relationship', function () {
    $channel = SlackChannel::factory()->create();

    expect($channel->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many threads relationship', function () {
    $channel = SlackChannel::factory()->create();

    expect($channel->threads())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('isClientChannel returns true for client classification', function () {
    $channel = SlackChannel::factory()->create(['classification' => 'client']);

    expect($channel->isClientChannel())->toBeTrue();
});

test('isClientChannel returns true when client_id exists', function () {
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create(['client_id' => $client->id, 'classification' => 'project']);

    expect($channel->isClientChannel())->toBeTrue();
});

test('isClientChannel returns false for internal classification', function () {
    $channel = SlackChannel::factory()->create(['classification' => 'internal', 'client_id' => null]);

    expect($channel->isClientChannel())->toBeFalse();
});

test('classifyAutomatically returns internal for internal channels', function () {
    $channel = SlackChannel::factory()->create(['channel_name' => 'internal-team']);

    expect($channel->classifyAutomatically())->toBe('internal');
});

test('classifyAutomatically returns client for shared channels', function () {
    $channel = SlackChannel::factory()->create(['is_shared' => true]);

    expect($channel->classifyAutomatically())->toBe('client');
});

test('classifyAutomatically returns general for general channel', function () {
    $channel = SlackChannel::factory()->create(['channel_name' => 'general']);

    expect($channel->classifyAutomatically())->toBe('general');
});

test('can be created via factory', function () {
    $channel = SlackChannel::factory()->create();

    expect($channel)->toBeInstanceOf(SlackChannel::class)
        ->and($channel->exists)->toBeTrue();
});
