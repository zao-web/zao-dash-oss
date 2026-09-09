<?php

use App\Models\SlackMessage;

test('has guarded attributes empty', function () {
    expect((new SlackMessage)->getGuarded())->toBe(['*']);
});

test('casts attachments to array', function () {
    $message = SlackMessage::factory()->create(['attachments' => [['type' => 'image']]]);

    expect($message->attachments)->toBeArray();
});

test('casts user_is_external to boolean', function () {
    $message = SlackMessage::factory()->create(['user_is_external' => true]);

    expect($message->user_is_external)->toBeTrue();
});

test('casts has_action_item to boolean', function () {
    $message = SlackMessage::factory()->create(['has_action_item' => false]);

    expect($message->has_action_item)->toBeFalse();
});

test('casts action_item_confidence to decimal', function () {
    $message = SlackMessage::factory()->create(['action_item_confidence' => 0.85]);

    expect($message->action_item_confidence)->toBeFloat();
});

test('casts is_repeated_request to boolean', function () {
    $message = SlackMessage::factory()->create(['is_repeated_request' => true]);

    expect($message->is_repeated_request)->toBeTrue();
});

test('casts processed_at to datetime', function () {
    $message = SlackMessage::factory()->create(['processed_at' => now()]);

    expect($message->processed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to workspace relationship', function () {
    $message = SlackMessage::factory()->create();

    expect($message->workspace())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to channel relationship', function () {
    $message = SlackMessage::factory()->create();

    expect($message->channel())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $message = SlackMessage::factory()->create();

    expect($message->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isThreadReply returns true when message is a thread reply', function () {
    $message = SlackMessage::factory()->create(['thread_ts' => '1234.5678', 'message_ts' => '1234.9999']);

    expect($message->isThreadReply())->toBeTrue();
});

test('isThreadReply returns false when thread_ts equals message_ts', function () {
    $message = SlackMessage::factory()->create(['thread_ts' => '1234.5678', 'message_ts' => '1234.5678']);

    expect($message->isThreadReply())->toBeFalse();
});

test('isFromExternalUser returns correct value', function () {
    $externalMessage = SlackMessage::factory()->create(['user_is_external' => true]);
    $internalMessage = SlackMessage::factory()->create(['user_is_external' => false]);

    expect($externalMessage->isFromExternalUser())->toBeTrue()
        ->and($internalMessage->isFromExternalUser())->toBeFalse();
});

test('needsProcessing returns true when not processed', function () {
    $message = SlackMessage::factory()->create(['processed_at' => null]);

    expect($message->needsProcessing())->toBeTrue();
});

test('needsProcessing returns false when processed', function () {
    $message = SlackMessage::factory()->create(['processed_at' => now()]);

    expect($message->needsProcessing())->toBeFalse();
});

test('can be created via factory', function () {
    $message = SlackMessage::factory()->create();

    expect($message)->toBeInstanceOf(SlackMessage::class)
        ->and($message->exists)->toBeTrue();
});
