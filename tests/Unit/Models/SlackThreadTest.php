<?php

use App\Models\SlackThread;

test('has guarded attributes empty', function () {
    expect((new SlackThread)->getGuarded())->toBe(['*']);
});

test('casts participants to array', function () {
    $thread = SlackThread::factory()->create(['participants' => ['user1', 'user2']]);

    expect($thread->participants)->toBeArray()
        ->and($thread->participants)->toBe(['user1', 'user2']);
});

test('casts action_items_extracted to array', function () {
    $thread = SlackThread::factory()->create(['action_items_extracted' => ['task1', 'task2']]);

    expect($thread->action_items_extracted)->toBeArray();
});

test('casts has_external_participant to boolean', function () {
    $thread = SlackThread::factory()->create(['has_external_participant' => true]);

    expect($thread->has_external_participant)->toBeTrue();
});

test('casts last_reply_at to datetime', function () {
    $thread = SlackThread::factory()->create(['last_reply_at' => now()]);

    expect($thread->last_reply_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to channel relationship', function () {
    $thread = SlackThread::factory()->create();

    expect($thread->channel())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many messages relationship', function () {
    $thread = SlackThread::factory()->create();

    expect($thread->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('needsSummarization returns true when no summary and message count high', function () {
    $thread = SlackThread::factory()->create(['message_count' => 15, 'summary' => null]);

    expect($thread->needsSummarization())->toBeTrue();
});

test('needsSummarization returns false when summary exists', function () {
    $thread = SlackThread::factory()->create(['message_count' => 15, 'summary' => 'Summary text']);

    expect($thread->needsSummarization())->toBeFalse();
});

test('needsSummarization returns false when message count low', function () {
    $thread = SlackThread::factory()->create(['message_count' => 5, 'summary' => null]);

    expect($thread->needsSummarization())->toBeFalse();
});

test('hasClientParticipant returns correct value', function () {
    $withClient = SlackThread::factory()->create(['has_external_participant' => true]);
    $withoutClient = SlackThread::factory()->create(['has_external_participant' => false]);

    expect($withClient->hasClientParticipant())->toBeTrue()
        ->and($withoutClient->hasClientParticipant())->toBeFalse();
});

test('can be created via factory', function () {
    $thread = SlackThread::factory()->create();

    expect($thread)->toBeInstanceOf(SlackThread::class)
        ->and($thread->exists)->toBeTrue();
});
