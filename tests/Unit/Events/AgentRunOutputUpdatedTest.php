<?php

use App\Events\AgentRunOutputUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

test('event implements ShouldBroadcast interface', function () {
    expect(AgentRunOutputUpdated::class)->toImplement(ShouldBroadcast::class);
});

test('event contains run ID, agent ID, chunk, and completion status', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Test output chunk',
        isComplete: false
    );

    expect($event->runId)->toBe(123);
    expect($event->agentId)->toBe(456);
    expect($event->chunk)->toBe('Test output chunk');
    expect($event->isComplete)->toBe(false);
});

test('event broadcasts on run-specific private channel', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Test',
        isComplete: false
    );

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-agent-runs.123');
});

test('event broadcasts with correct data structure', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Processing data...',
        isComplete: false
    );

    $broadcastData = $event->broadcastWith();

    expect($broadcastData)->toHaveKeys(['run_id', 'agent_id', 'chunk', 'is_complete']);
    expect($broadcastData['run_id'])->toBe(123);
    expect($broadcastData['agent_id'])->toBe(456);
    expect($broadcastData['chunk'])->toBe('Processing data...');
    expect($broadcastData['is_complete'])->toBe(false);
});

test('event broadcasts with custom event name', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Test',
        isComplete: false
    );

    expect($event->broadcastAs())->toBe('run.output.updated');
});

test('event handles completion flag correctly', function () {
    $completeEvent = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Done!',
        isComplete: true
    );

    $incompleteEvent = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Still working...',
        isComplete: false
    );

    expect($completeEvent->isComplete)->toBe(true);
    expect($completeEvent->broadcastWith()['is_complete'])->toBe(true);

    expect($incompleteEvent->isComplete)->toBe(false);
    expect($incompleteEvent->broadcastWith()['is_complete'])->toBe(false);
});

test('event defaults isComplete to false', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Test'
    );

    expect($event->isComplete)->toBe(false);
    expect($event->broadcastWith()['is_complete'])->toBe(false);
});

test('event can handle empty chunks', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: '',
        isComplete: false
    );

    expect($event->chunk)->toBe('');
    expect($event->broadcastWith()['chunk'])->toBe('');
});

test('event can handle multiline chunks', function () {
    $multilineChunk = "Line 1\nLine 2\nLine 3";

    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: $multilineChunk,
        isComplete: false
    );

    expect($event->chunk)->toBe($multilineChunk);
    expect($event->broadcastWith()['chunk'])->toContain("\n");
});

test('event can handle JSON chunks', function () {
    $jsonChunk = json_encode(['status' => 'processing', 'progress' => 50]);

    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: $jsonChunk,
        isComplete: false
    );

    expect($event->chunk)->toBe($jsonChunk);
    expect($event->broadcastWith()['chunk'])->toBeJson();
});

test('event can handle special characters in chunks', function () {
    $specialChunk = "Test with émojis 🚀 and special chars: <>&\"'";

    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: $specialChunk,
        isComplete: false
    );

    expect($event->chunk)->toBe($specialChunk);
    expect($event->broadcastWith()['chunk'])->toBe($specialChunk);
});

test('event serializes correctly for queue', function () {
    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: 'Test chunk',
        isComplete: true
    );

    $serialized = serialize($event);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(AgentRunOutputUpdated::class);
    expect($unserialized->runId)->toBe(123);
    expect($unserialized->agentId)->toBe(456);
    expect($unserialized->chunk)->toBe('Test chunk');
    expect($unserialized->isComplete)->toBe(true);
});

test('event can be dispatched', function () {
    Event::fake();

    AgentRunOutputUpdated::dispatch(123, 456, 'Test output', false);

    Event::assertDispatched(AgentRunOutputUpdated::class, function ($event) {
        return $event->runId === 123
            && $event->agentId === 456
            && $event->chunk === 'Test output'
            && $event->isComplete === false;
    });
});

test('event broadcasts to different run channels correctly', function () {
    $event1 = new AgentRunOutputUpdated(runId: 100, agentId: 1, chunk: 'A');
    $event2 = new AgentRunOutputUpdated(runId: 200, agentId: 1, chunk: 'B');

    expect($event1->broadcastOn()[0]->name)->toBe('private-agent-runs.100');
    expect($event2->broadcastOn()[0]->name)->toBe('private-agent-runs.200');
});

test('event supports streaming use case with multiple chunks', function () {
    $chunks = ['Chunk 1', 'Chunk 2', 'Chunk 3', 'Final chunk'];

    foreach ($chunks as $index => $chunk) {
        $isComplete = $index === count($chunks) - 1;

        $event = new AgentRunOutputUpdated(
            runId: 123,
            agentId: 456,
            chunk: $chunk,
            isComplete: $isComplete
        );

        expect($event->chunk)->toBe($chunk);
        expect($event->isComplete)->toBe($isComplete);
    }
});

test('event handles large chunks', function () {
    $largeChunk = str_repeat('Lorem ipsum dolor sit amet. ', 1000);

    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: $largeChunk,
        isComplete: false
    );

    expect($event->chunk)->toBe($largeChunk);
    expect(strlen($event->broadcastWith()['chunk']))->toBeGreaterThan(10000);
});

test('event preserves whitespace in chunks', function () {
    $chunkWithWhitespace = "  Indented text\n    More indent\t\tTabs";

    $event = new AgentRunOutputUpdated(
        runId: 123,
        agentId: 456,
        chunk: $chunkWithWhitespace,
        isComplete: false
    );

    expect($event->chunk)->toBe($chunkWithWhitespace);
    expect($event->broadcastWith()['chunk'])->toBe($chunkWithWhitespace);
});
