<?php

use App\Events\AgentRunStatusChanged;
use App\Models\AgentRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('event implements ShouldBroadcast interface', function () {
    expect(AgentRunStatusChanged::class)->toImplement(ShouldBroadcast::class);
});

test('event contains agent run and previous status', function () {
    $run = AgentRun::factory()->create(['status' => 'running']);
    $event = new AgentRunStatusChanged($run, 'pending');

    expect($event->run)->toBe($run);
    expect($event->previousStatus)->toBe('pending');
});

test('event broadcasts on correct channels', function () {
    $run = AgentRun::factory()->create(['status' => 'running']);
    $event = new AgentRunStatusChanged($run, 'pending');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(3);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-agents.'.$run->agent_id);
    expect($channels[1])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[1]->name)->toBe('private-agent-runs.'.$run->id);
    expect($channels[2])->toBeInstanceOf(Channel::class);
    expect($channels[2]->name)->toBe('agents');
});

test('event broadcasts with correct data structure', function () {
    $run = AgentRun::factory()->completed()->create();
    $event = new AgentRunStatusChanged($run, 'running');

    $broadcastData = $event->broadcastWith();

    expect($broadcastData)->toHaveKeys([
        'run_id',
        'agent_id',
        'agent_slug',
        'agent_name',
        'status',
        'previous_status',
        'started_at',
        'completed_at',
        'cost_usd',
        'invocation_source',
    ]);

    expect($broadcastData['run_id'])->toBe($run->id);
    expect($broadcastData['agent_id'])->toBe($run->agent_id);
    expect($broadcastData['agent_slug'])->toBe($run->agent->slug);
    expect($broadcastData['agent_name'])->toBe($run->agent->name);
    expect($broadcastData['status'])->toBe($run->status);
    expect($broadcastData['previous_status'])->toBe('running');
});

test('event broadcasts with custom event name', function () {
    $run = AgentRun::factory()->create();
    $event = new AgentRunStatusChanged($run, 'pending');

    expect($event->broadcastAs())->toBe('run.status.changed');
});

test('event serializes correctly for queue', function () {
    $run = AgentRun::factory()->create(['status' => 'completed']);
    $event = new AgentRunStatusChanged($run, 'running');

    // Test that event can be serialized (important for queued broadcasting)
    $serialized = serialize($event);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(AgentRunStatusChanged::class);
    expect($unserialized->run->id)->toBe($run->id);
    expect($unserialized->previousStatus)->toBe('running');
});

test('event includes nullable completed_at in broadcast data', function () {
    $run = AgentRun::factory()->create([
        'status' => 'running',
        'started_at' => now(),
        'completed_at' => null,
    ]);
    $event = new AgentRunStatusChanged($run, 'running');

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['started_at'])->not->toBeNull();
    expect($broadcastData['completed_at'])->toBeNull();
});

test('event formats timestamps as ISO8601 strings', function () {
    $run = AgentRun::factory()->completed()->create([
        'started_at' => now(),
        'completed_at' => now(),
    ]);
    $event = new AgentRunStatusChanged($run, 'running');

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['started_at'])->toBeString();
    expect($broadcastData['completed_at'])->toBeString();
    // Verify ISO8601 format
    expect($broadcastData['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('event includes cost information in broadcast data', function () {
    $run = AgentRun::factory()->completed()->create(['cost_usd' => 2.5]);
    $event = new AgentRunStatusChanged($run, 'running');

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['cost_usd'])->toBe('2.5000');
});

test('event includes invocation source in broadcast data', function () {
    $run = AgentRun::factory()->create(['invocation_source' => 'webhook']);
    $event = new AgentRunStatusChanged($run, 'pending');

    $broadcastData = $event->broadcastWith();

    expect($broadcastData['invocation_source'])->toBe('webhook');
});

test('event can be dispatched', function () {
    Event::fake();

    $run = AgentRun::factory()->create();
    AgentRunStatusChanged::dispatch($run, 'pending');

    Event::assertDispatched(AgentRunStatusChanged::class, function ($event) use ($run) {
        return $event->run->id === $run->id && $event->previousStatus === 'pending';
    });
});

test('event broadcasts to agent-specific private channel', function () {
    $run = AgentRun::factory()->create();
    $event = new AgentRunStatusChanged($run, 'pending');

    $channels = $event->broadcastOn();
    $agentChannel = collect($channels)->first(fn ($ch) => str_contains($ch->name, 'agents.'.$run->agent_id));

    expect($agentChannel)->not->toBeNull();
    expect($agentChannel)->toBeInstanceOf(PrivateChannel::class);
});

test('event broadcasts to run-specific private channel', function () {
    $run = AgentRun::factory()->create();
    $event = new AgentRunStatusChanged($run, 'pending');

    $channels = $event->broadcastOn();
    $runChannel = collect($channels)->first(fn ($ch) => str_contains($ch->name, 'agent-runs.'.$run->id));

    expect($runChannel)->not->toBeNull();
    expect($runChannel)->toBeInstanceOf(PrivateChannel::class);
});

test('event broadcasts to public agents channel for dashboard', function () {
    $run = AgentRun::factory()->create();
    $event = new AgentRunStatusChanged($run, 'pending');

    $channels = $event->broadcastOn();
    $publicChannel = collect($channels)->first(fn ($ch) => $ch->name === 'agents' && $ch instanceof Channel && ! $ch instanceof PrivateChannel);

    expect($publicChannel)->not->toBeNull();
});

test('event handles different status transitions', function () {
    $transitions = [
        ['from' => 'pending', 'to' => 'running'],
        ['from' => 'running', 'to' => 'completed'],
        ['from' => 'running', 'to' => 'failed'],
        ['from' => 'pending', 'to' => 'pending_approval'],
    ];

    foreach ($transitions as $transition) {
        $run = AgentRun::factory()->create(['status' => $transition['to']]);
        $event = new AgentRunStatusChanged($run, $transition['from']);

        $broadcastData = $event->broadcastWith();

        expect($broadcastData['status'])->toBe($transition['to']);
        expect($broadcastData['previous_status'])->toBe($transition['from']);
    }
});
