<?php

use App\Models\Agent;
use App\Models\AgentRun;

test('has guarded attributes empty', function () {
    expect((new AgentRun)->getGuarded())->toBe(['*']);
});

test('casts context to array', function () {
    $run = AgentRun::factory()->create(['context' => ['key' => 'value']]);

    expect($run->context)->toBeArray()
        ->and($run->context)->toBe(['key' => 'value']);
});

test('casts output to array', function () {
    $run = AgentRun::factory()->create(['output' => ['result' => 'success']]);

    expect($run->output)->toBeArray();
});

test('casts trigger_metadata to array', function () {
    $run = AgentRun::factory()->create(['trigger_metadata' => ['source' => 'webhook']]);

    expect($run->trigger_metadata)->toBeArray();
});

test('casts cost_usd to decimal', function () {
    $run = AgentRun::factory()->create(['cost_usd' => 1.2345]);

    expect($run->cost_usd)->toBeFloat();
});

test('casts started_at to datetime', function () {
    $run = AgentRun::factory()->create(['started_at' => now()]);

    expect($run->started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts completed_at to datetime', function () {
    $run = AgentRun::factory()->create(['completed_at' => now()]);

    expect($run->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to agent relationship', function () {
    $run = AgentRun::factory()->create();

    expect($run->agent())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has one approval request relationship', function () {
    $run = AgentRun::factory()->create();

    expect($run->approvalRequest())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('belongs to chain run relationship', function () {
    $run = AgentRun::factory()->create();

    expect($run->chainRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isPartOfChain returns true when chain_run_id exists', function () {
    $run = AgentRun::factory()->create(['chain_run_id' => 1]);

    expect($run->isPartOfChain())->toBeTrue();
});

test('isPartOfChain returns false when chain_run_id is null', function () {
    $run = AgentRun::factory()->create(['chain_run_id' => null]);

    expect($run->isPartOfChain())->toBeFalse();
});

test('scopeFromSource filters by source', function () {
    $agent = Agent::factory()->create();
    AgentRun::factory()->create(['agent_id' => $agent->id, 'invocation_source' => 'manual']);
    AgentRun::factory()->create(['agent_id' => $agent->id, 'invocation_source' => 'webhook']);

    $manualRuns = AgentRun::fromSource('manual')->count();

    expect($manualRuns)->toBe(1);
});

test('isAutomated returns true for webhook source', function () {
    $run = AgentRun::factory()->create(['invocation_source' => AgentRun::SOURCE_WEBHOOK]);

    expect($run->isAutomated())->toBeTrue();
});

test('isAutomated returns true for scheduled source', function () {
    $run = AgentRun::factory()->create(['invocation_source' => AgentRun::SOURCE_SCHEDULED]);

    expect($run->isAutomated())->toBeTrue();
});

test('isAutomated returns true for chained source', function () {
    $run = AgentRun::factory()->create(['invocation_source' => AgentRun::SOURCE_CHAINED]);

    expect($run->isAutomated())->toBeTrue();
});

test('isAutomated returns false for manual source', function () {
    $run = AgentRun::factory()->create(['invocation_source' => AgentRun::SOURCE_MANUAL]);

    expect($run->isAutomated())->toBeFalse();
});

test('can be created via factory', function () {
    $run = AgentRun::factory()->create();

    expect($run)->toBeInstanceOf(AgentRun::class)
        ->and($run->exists)->toBeTrue();
});
