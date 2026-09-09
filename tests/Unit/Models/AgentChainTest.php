<?php

use App\Models\AgentChain;

test('has guarded attributes empty', function () {
    expect((new AgentChain)->getGuarded())->toBe(['*']);
});

test('casts is_active to boolean', function () {
    $chain = AgentChain::factory()->create(['is_active' => true]);

    expect($chain->is_active)->toBeTrue();
});

test('casts steps to array', function () {
    $steps = [
        ['agent_slug' => 'test-agent', 'condition' => null, 'transform' => null],
    ];
    $chain = AgentChain::factory()->create(['steps' => $steps]);

    expect($chain->steps)->toBeArray()
        ->and($chain->steps)->toBe($steps);
});

test('casts metadata to array', function () {
    $chain = AgentChain::factory()->create(['metadata' => ['key' => 'value']]);

    expect($chain->metadata)->toBeArray();
});

test('has many runs relationship', function () {
    $chain = AgentChain::factory()->create();

    expect($chain->runs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('getStepCount returns correct count', function () {
    $chain = AgentChain::factory()->create([
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null, 'transform' => null],
            ['agent_slug' => 'agent-2', 'condition' => null, 'transform' => null],
        ],
    ]);

    expect($chain->getStepCount())->toBe(2);
});

test('shouldExecuteStep returns true when no condition', function () {
    $chain = AgentChain::factory()->create([
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null, 'transform' => null],
        ],
    ]);

    expect($chain->shouldExecuteStep(0, null, true))->toBeTrue();
});

test('shouldExecuteStep evaluates previous_success condition', function () {
    $chain = AgentChain::factory()->create([
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => 'previous_success', 'transform' => null],
        ],
    ]);

    expect($chain->shouldExecuteStep(0, 'output', true))->toBeTrue()
        ->and($chain->shouldExecuteStep(0, 'output', false))->toBeFalse();
});

test('shouldExecuteStep evaluates output_contains condition', function () {
    $chain = AgentChain::factory()->create([
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => 'output_contains:success', 'transform' => null],
        ],
    ]);

    expect($chain->shouldExecuteStep(0, 'The operation was a success', true))->toBeTrue()
        ->and($chain->shouldExecuteStep(0, 'The operation failed', true))->toBeFalse();
});

test('templates returns predefined templates', function () {
    $templates = AgentChain::templates();

    expect($templates)->toBeArray()
        ->and($templates)->toHaveKey('content_pipeline')
        ->and($templates)->toHaveKey('lead_nurture_pipeline')
        ->and($templates)->toHaveKey('client_health_response');
});

test('can be created via factory', function () {
    $chain = AgentChain::factory()->create();

    expect($chain)->toBeInstanceOf(AgentChain::class)
        ->and($chain->exists)->toBeTrue();
});
