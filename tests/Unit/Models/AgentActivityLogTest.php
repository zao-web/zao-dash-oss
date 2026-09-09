<?php

use App\Models\AgentActivityLog;

test('has guarded attributes empty', function () {
    expect((new AgentActivityLog)->getGuarded())->toBe(['*']);
});

test('casts changes to array', function () {
    $log = AgentActivityLog::factory()->create(['changes' => ['field' => 'value']]);

    expect($log->changes)->toBeArray()
        ->and($log->changes)->toBe(['field' => 'value']);
});

test('casts metadata to array', function () {
    $log = AgentActivityLog::factory()->create(['metadata' => ['key' => 'value']]);

    expect($log->metadata)->toBeArray();
});

test('belongs to agent relationship', function () {
    $log = AgentActivityLog::factory()->create();

    expect($log->agent())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to user relationship', function () {
    $log = AgentActivityLog::factory()->create();

    expect($log->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has action constants', function () {
    expect(AgentActivityLog::ACTION_CREATED)->toBe('created')
        ->and(AgentActivityLog::ACTION_UPDATED)->toBe('updated')
        ->and(AgentActivityLog::ACTION_DELETED)->toBe('deleted')
        ->and(AgentActivityLog::ACTION_STATUS_CHANGED)->toBe('status_changed')
        ->and(AgentActivityLog::ACTION_TRIGGERED)->toBe('triggered')
        ->and(AgentActivityLog::ACTION_CLONED)->toBe('cloned')
        ->and(AgentActivityLog::ACTION_CLONED_FROM)->toBe('cloned_from');
});

test('can be created via factory', function () {
    $log = AgentActivityLog::factory()->create();

    expect($log)->toBeInstanceOf(AgentActivityLog::class)
        ->and($log->exists)->toBeTrue();
});
