<?php

use App\Models\AgentChainRun;

test('has guarded attributes empty', function () {
    expect((new AgentChainRun)->getGuarded())->toBe(['*']);
});

test('casts step_results to array', function () {
    $run = AgentChainRun::factory()->create(['step_results' => [['status' => 'completed']]]);

    expect($run->step_results)->toBeArray();
});

test('casts started_at to datetime', function () {
    $run = AgentChainRun::factory()->create(['started_at' => now()]);

    expect($run->started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts completed_at to datetime', function () {
    $run = AgentChainRun::factory()->create(['completed_at' => now()]);

    expect($run->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('has status constants', function () {
    expect(AgentChainRun::STATUS_PENDING)->toBe('pending')
        ->and(AgentChainRun::STATUS_RUNNING)->toBe('running')
        ->and(AgentChainRun::STATUS_COMPLETED)->toBe('completed')
        ->and(AgentChainRun::STATUS_FAILED)->toBe('failed')
        ->and(AgentChainRun::STATUS_CANCELLED)->toBe('cancelled');
});

test('belongs to chain relationship', function () {
    $run = AgentChainRun::factory()->create();

    expect($run->chain())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many agent runs relationship', function () {
    $run = AgentChainRun::factory()->create();

    expect($run->agentRuns())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('getCurrentStep returns current step', function () {
    $run = AgentChainRun::factory()->create(['current_step' => 2]);

    expect($run->getCurrentStep())->toBe(2);
});

test('getCurrentStep returns 0 when null', function () {
    $run = AgentChainRun::factory()->create(['current_step' => null]);

    expect($run->getCurrentStep())->toBe(0);
});

test('wasPreviousStepSuccessful returns true for first step', function () {
    $run = AgentChainRun::factory()->create(['current_step' => 0]);

    expect($run->wasPreviousStepSuccessful())->toBeTrue();
});

test('can be created via factory', function () {
    $run = AgentChainRun::factory()->create();

    expect($run)->toBeInstanceOf(AgentChainRun::class)
        ->and($run->exists)->toBeTrue();
});
