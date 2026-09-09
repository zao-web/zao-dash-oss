<?php

use App\Models\SlackRequestPattern;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new SlackRequestPattern)->getGuarded())->toBe([]);
});

test('casts topic_embedding to array', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'topic_embedding' => [0.1, 0.2, 0.3],
    ]);

    expect($pattern->topic_embedding)->toBeArray()
        ->and($pattern->topic_embedding)->toBe([0.1, 0.2, 0.3]);
});

test('casts messages to array', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'messages' => ['msg1', 'msg2'],
    ]);

    expect($pattern->messages)->toBeArray()
        ->and($pattern->messages)->toBe(['msg1', 'msg2']);
});

test('casts first_asked_at to datetime', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'first_asked_at' => now(),
    ]);

    expect($pattern->first_asked_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts last_asked_at to datetime', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'last_asked_at' => now(),
    ]);

    expect($pattern->last_asked_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts resolved_at to datetime', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'resolved_at' => now(),
    ]);

    expect($pattern->resolved_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts is_resolved to boolean', function () {
    $pattern = SlackRequestPattern::factory()->create(['is_resolved' => true]);

    expect($pattern->is_resolved)->toBeTrue();
});

test('belongs to client relationship', function () {
    $pattern = SlackRequestPattern::factory()->create();

    expect($pattern->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getHealthImpact returns 0 when resolved', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'is_resolved' => true,
        'ask_count' => 5,
    ]);

    expect($pattern->getHealthImpact())->toBe(0.0);
});

test('getHealthImpact returns 0 when ask_count is 1', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'is_resolved' => false,
        'ask_count' => 1,
    ]);

    expect($pattern->getHealthImpact())->toBe(0.0);
});

test('getHealthImpact calculates impact for repeat questions', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'is_resolved' => false,
        'ask_count' => 3,
    ]);

    expect($pattern->getHealthImpact())->toBe(1.0);
});

test('getHealthImpact caps at 2.0', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'is_resolved' => false,
        'ask_count' => 10,
    ]);

    expect($pattern->getHealthImpact())->toBe(2.0);
});

test('getSeverityLevel returns critical for high ask count', function () {
    $pattern = SlackRequestPattern::factory()->create(['ask_count' => 4]);

    expect($pattern->getSeverityLevel())->toBe('critical');
});

test('getSeverityLevel returns high for moderate ask count', function () {
    $pattern = SlackRequestPattern::factory()->create(['ask_count' => 3]);

    expect($pattern->getSeverityLevel())->toBe('high');
});

test('getSeverityLevel returns warning for low ask count', function () {
    $pattern = SlackRequestPattern::factory()->create(['ask_count' => 2]);

    expect($pattern->getSeverityLevel())->toBe('warning');
});

test('getSeverityLevel returns normal for single ask', function () {
    $pattern = SlackRequestPattern::factory()->create(['ask_count' => 1]);

    expect($pattern->getSeverityLevel())->toBe('normal');
});

test('markResolved updates is_resolved and resolved_at', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'is_resolved' => false,
        'resolved_at' => null,
    ]);

    $pattern->markResolved();

    expect($pattern->fresh()->is_resolved)->toBeTrue()
        ->and($pattern->fresh()->resolved_at)->not->toBeNull();
});

test('addMessage adds message to array and updates counts', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'messages' => ['msg1'],
        'ask_count' => 1,
    ]);

    $pattern->addMessage('msg2');

    $fresh = $pattern->fresh();
    expect($fresh->messages)->toBe(['msg1', 'msg2'])
        ->and($fresh->ask_count)->toBe(2)
        ->and($fresh->last_asked_at)->not->toBeNull();
});

test('addMessage handles null messages array', function () {
    $pattern = SlackRequestPattern::factory()->create([
        'messages' => null,
        'ask_count' => 0,
    ]);

    $pattern->addMessage('msg1');

    $fresh = $pattern->fresh();
    expect($fresh->messages)->toBe(['msg1'])
        ->and($fresh->ask_count)->toBe(1);
});

test('can be created via factory', function () {
    $pattern = SlackRequestPattern::factory()->create();

    expect($pattern)->toBeInstanceOf(SlackRequestPattern::class)
        ->and($pattern->exists)->toBeTrue();
});
