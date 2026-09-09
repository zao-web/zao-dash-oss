<?php

use App\Models\AgentRun;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('casts conversation_history to array', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [['role' => 'user', 'content' => 'hello']],
    ]);

    expect($context->conversation_history)->toBeArray()
        ->and($context->conversation_history[0]['role'])->toBe('user');
});

test('casts extracted_intents to array', function () {
    $context = SlackThreadContext::factory()->create([
        'extracted_intents' => ['create_task', 'log_note'],
    ]);

    expect($context->extracted_intents)->toBeArray()
        ->and($context->extracted_intents)->toContain('create_task');
});

test('casts pending_actions to array', function () {
    $context = SlackThreadContext::factory()->create([
        'pending_actions' => [['type' => 'create_task', 'data' => []]],
    ]);

    expect($context->pending_actions)->toBeArray()
        ->and($context->pending_actions[0]['type'])->toBe('create_task');
});

test('casts completed_actions to array', function () {
    $context = SlackThreadContext::factory()->create([
        'completed_actions' => [['type' => 'log_note', 'result' => ['success' => true]]],
    ]);

    expect($context->completed_actions)->toBeArray()
        ->and($context->completed_actions[0]['result']['success'])->toBeTrue();
});

test('casts last_interaction_at to datetime', function () {
    $context = SlackThreadContext::factory()->create([
        'last_interaction_at' => now(),
    ]);

    expect($context->last_interaction_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts expires_at to datetime', function () {
    $context = SlackThreadContext::factory()->create([
        'expires_at' => now()->addDay(),
    ]);

    expect($context->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to channel relationship', function () {
    $context = SlackThreadContext::factory()->create();

    expect($context->channel())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($context->channel)->toBeInstanceOf(SlackChannel::class);
});

test('belongs to agentRun relationship', function () {
    $run = AgentRun::factory()->create();
    $context = SlackThreadContext::factory()->create(['agent_run_id' => $run->id]);

    expect($context->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($context->agentRun)->toBeInstanceOf(AgentRun::class);
});

test('isExpired returns true when expires_at is in the past', function () {
    $context = SlackThreadContext::factory()->expired()->create();

    expect($context->isExpired())->toBeTrue();
});

test('isExpired returns false when expires_at is in the future', function () {
    $context = SlackThreadContext::factory()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect($context->isExpired())->toBeFalse();
});

test('isExpired returns false when expires_at is null', function () {
    $context = SlackThreadContext::factory()->create([
        'expires_at' => null,
    ]);

    expect($context->isExpired())->toBeFalse();
});

test('isActive returns true for awaiting_response state', function () {
    $context = SlackThreadContext::factory()->awaitingResponse()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect($context->isActive())->toBeTrue();
});

test('isActive returns true for processing state', function () {
    $context = SlackThreadContext::factory()->processing()->create([
        'expires_at' => now()->addHour(),
    ]);

    expect($context->isActive())->toBeTrue();
});

test('isActive returns false for idle state', function () {
    $context = SlackThreadContext::factory()->create([
        'current_state' => 'idle',
        'expires_at' => now()->addHour(),
    ]);

    expect($context->isActive())->toBeFalse();
});

test('isActive returns false when expired', function () {
    $context = SlackThreadContext::factory()->awaitingResponse()->expired()->create();

    expect($context->isActive())->toBeFalse();
});

test('addToHistory appends message and updates last_interaction_at', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [],
        'last_interaction_at' => now()->subHour(),
    ]);

    $oldInteraction = $context->last_interaction_at;

    $context->addToHistory('user', 'Hello bot');

    $context->refresh();
    expect($context->conversation_history)->toHaveCount(1)
        ->and($context->conversation_history[0]['role'])->toBe('user')
        ->and($context->conversation_history[0]['content'])->toBe('Hello bot')
        ->and($context->conversation_history[0])->toHaveKey('timestamp')
        ->and($context->last_interaction_at->gt($oldInteraction))->toBeTrue();
});

test('addToHistory preserves existing history', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [
            ['role' => 'user', 'content' => 'First message', 'timestamp' => now()->subMinutes(5)->toIso8601String()],
        ],
    ]);

    $context->addToHistory('assistant', 'Second message');

    $context->refresh();
    expect($context->conversation_history)->toHaveCount(2)
        ->and($context->conversation_history[0]['content'])->toBe('First message')
        ->and($context->conversation_history[1]['content'])->toBe('Second message');
});

test('addPendingAction adds action with uuid and timestamp', function () {
    $context = SlackThreadContext::factory()->create([
        'pending_actions' => [],
    ]);

    $context->addPendingAction('trigger_agent', ['agent_slug' => 'dev-agent']);

    $context->refresh();
    expect($context->pending_actions)->toHaveCount(1)
        ->and($context->pending_actions[0]['type'])->toBe('trigger_agent')
        ->and($context->pending_actions[0]['data']['agent_slug'])->toBe('dev-agent')
        ->and($context->pending_actions[0])->toHaveKey('id')
        ->and($context->pending_actions[0])->toHaveKey('created_at');
});

test('addPendingAction preserves existing actions', function () {
    $context = SlackThreadContext::factory()->create([
        'pending_actions' => [
            ['id' => 'existing-id', 'type' => 'create_task', 'data' => [], 'created_at' => now()->toIso8601String()],
        ],
    ]);

    $context->addPendingAction('log_note', ['content' => 'test']);

    $context->refresh();
    expect($context->pending_actions)->toHaveCount(2)
        ->and($context->pending_actions[0]['id'])->toBe('existing-id')
        ->and($context->pending_actions[1]['type'])->toBe('log_note');
});

test('markActionCompleted moves action from pending to completed', function () {
    $actionId = 'test-action-id';
    $context = SlackThreadContext::factory()->create([
        'pending_actions' => [
            ['id' => $actionId, 'type' => 'create_task', 'data' => ['title' => 'Test'], 'created_at' => now()->toIso8601String()],
        ],
        'completed_actions' => [],
    ]);

    $context->markActionCompleted($actionId, ['task_id' => 123]);

    $context->refresh();
    expect($context->pending_actions)->toHaveCount(0)
        ->and($context->completed_actions)->toHaveCount(1)
        ->and($context->completed_actions[0]['id'])->toBe($actionId)
        ->and($context->completed_actions[0]['result']['task_id'])->toBe(123)
        ->and($context->completed_actions[0])->toHaveKey('completed_at');
});

test('markActionCompleted does nothing for non-existent action', function () {
    $context = SlackThreadContext::factory()->create([
        'pending_actions' => [
            ['id' => 'existing-id', 'type' => 'create_task', 'data' => [], 'created_at' => now()->toIso8601String()],
        ],
        'completed_actions' => [],
    ]);

    $context->markActionCompleted('non-existent-id', []);

    $context->refresh();
    expect($context->pending_actions)->toHaveCount(1)
        ->and($context->completed_actions)->toHaveCount(0);
});

test('getRecentHistory returns last N messages', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [
            ['role' => 'user', 'content' => 'Message 1'],
            ['role' => 'assistant', 'content' => 'Message 2'],
            ['role' => 'user', 'content' => 'Message 3'],
            ['role' => 'assistant', 'content' => 'Message 4'],
            ['role' => 'user', 'content' => 'Message 5'],
        ],
    ]);

    $recent = $context->getRecentHistory(3);

    expect($recent)->toHaveCount(3)
        ->and($recent[0]['content'])->toBe('Message 3')
        ->and($recent[2]['content'])->toBe('Message 5');
});

test('getRecentHistory returns all when count is less than limit', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [
            ['role' => 'user', 'content' => 'Message 1'],
            ['role' => 'assistant', 'content' => 'Message 2'],
        ],
    ]);

    $recent = $context->getRecentHistory(10);

    expect($recent)->toHaveCount(2);
});

test('getRecentHistory returns empty array for empty history', function () {
    $context = SlackThreadContext::factory()->create([
        'conversation_history' => [],
    ]);

    $recent = $context->getRecentHistory(5);

    expect($recent)->toBeArray()->toBeEmpty();
});

test('findOrCreateForThread creates new context when not exists', function () {
    $channel = SlackChannel::factory()->create();
    $threadTs = '1234567890.123456';
    $botUserId = 'U12345BOT';

    $context = SlackThreadContext::findOrCreateForThread($channel, $threadTs, $botUserId);

    expect($context)->toBeInstanceOf(SlackThreadContext::class)
        ->and($context->channel_id)->toBe($channel->id)
        ->and($context->thread_ts)->toBe($threadTs)
        ->and($context->bot_user_id)->toBe($botUserId)
        ->and($context->current_state)->toBe('idle')
        ->and($context->conversation_history)->toBeArray()->toBeEmpty();
});

test('findOrCreateForThread returns existing context', function () {
    $channel = SlackChannel::factory()->create();
    $threadTs = '1234567890.123456';
    $existingContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => $threadTs,
        'bot_user_id' => 'U12345BOT',
        'conversation_history' => [['role' => 'user', 'content' => 'Previous message']],
    ]);

    $context = SlackThreadContext::findOrCreateForThread($channel, $threadTs, 'UDIFFERENT');

    expect($context->id)->toBe($existingContext->id)
        ->and($context->bot_user_id)->toBe('U12345BOT')
        ->and($context->conversation_history)->toHaveCount(1);
});

test('can be created via factory', function () {
    $context = SlackThreadContext::factory()->create();

    expect($context)->toBeInstanceOf(SlackThreadContext::class)
        ->and($context->exists)->toBeTrue();
});

test('factory withHistory state works', function () {
    $history = [
        ['role' => 'user', 'content' => 'Test message'],
    ];

    $context = SlackThreadContext::factory()->withHistory($history)->create();

    expect($context->conversation_history)->toBe($history);
});

test('factory processing state works', function () {
    $context = SlackThreadContext::factory()->processing()->create();

    expect($context->current_state)->toBe('processing');
});

test('factory awaitingResponse state works', function () {
    $context = SlackThreadContext::factory()->awaitingResponse()->create();

    expect($context->current_state)->toBe('awaiting_response');
});

test('factory expired state works', function () {
    $context = SlackThreadContext::factory()->expired()->create();

    expect($context->isExpired())->toBeTrue();
});
