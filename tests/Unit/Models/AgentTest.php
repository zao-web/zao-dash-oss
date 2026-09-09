<?php

use App\Models\Agent;

test('has guarded attributes empty', function () {
    expect((new Agent)->getGuarded())->toBe(['*']);
});

test('casts requires_approval to boolean', function () {
    $agent = Agent::factory()->create(['requires_approval' => true]);

    expect($agent->requires_approval)->toBeTrue();
});

test('casts use_consortium to boolean', function () {
    $agent = Agent::factory()->create(['use_consortium' => false]);

    expect($agent->use_consortium)->toBeFalse();
});

test('casts consortium_config to array', function () {
    $agent = Agent::factory()->create(['consortium_config' => ['key' => 'value']]);

    expect($agent->consortium_config)->toBeArray()
        ->and($agent->consortium_config)->toBe(['key' => 'value']);
});

test('casts is_dynamic to boolean', function () {
    $agent = Agent::factory()->create(['is_dynamic' => true]);

    expect($agent->is_dynamic)->toBeTrue();
});

test('casts max_budget_usd to decimal', function () {
    $agent = Agent::factory()->create(['max_budget_usd' => 15.50]);

    expect($agent->max_budget_usd)->toBeFloat()
        ->and((string) $agent->max_budget_usd)->toBe('15.50');
});

test('casts allowed_tools to array', function () {
    $agent = Agent::factory()->create(['allowed_tools' => ['tool1', 'tool2']]);

    expect($agent->allowed_tools)->toBeArray()
        ->and($agent->allowed_tools)->toBe(['tool1', 'tool2']);
});

test('casts trigger_config to array', function () {
    $agent = Agent::factory()->create(['trigger_config' => ['trigger' => 'webhook']]);

    expect($agent->trigger_config)->toBeArray();
});

test('casts circuit_broken_at to datetime', function () {
    $agent = Agent::factory()->create(['circuit_broken_at' => now()]);

    expect($agent->circuit_broken_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts definition_synced_at to datetime', function () {
    $agent = Agent::factory()->create(['definition_synced_at' => now()]);

    expect($agent->definition_synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts webhook_enabled to boolean', function () {
    $agent = Agent::factory()->create(['webhook_enabled' => true]);

    expect($agent->webhook_enabled)->toBeTrue();
});

test('casts webhook_allowed_ips to array', function () {
    $agent = Agent::factory()->create(['webhook_allowed_ips' => ['127.0.0.1']]);

    expect($agent->webhook_allowed_ips)->toBeArray();
});

test('has many runs relationship', function () {
    $agent = Agent::factory()->create();

    expect($agent->runs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many activity logs relationship', function () {
    $agent = Agent::factory()->create();

    expect($agent->activityLogs())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('isRegistered returns true for non-dynamic agents', function () {
    $agent = Agent::factory()->create(['is_dynamic' => false]);

    expect($agent->isRegistered())->toBeTrue();
});

test('isRegistered returns false for dynamic agents', function () {
    $agent = Agent::factory()->create(['is_dynamic' => true]);

    expect($agent->isRegistered())->toBeFalse();
});

test('getExecutionConfig returns array for dynamic agent', function () {
    $agent = Agent::factory()->create([
        'is_dynamic' => true,
        'model' => 'sonnet',
        'allowed_tools' => ['tool1'],
        'system_prompt' => 'Test prompt',
        'max_budget_usd' => 10.00,
        'requires_approval' => true,
    ]);

    $config = $agent->getExecutionConfig();

    expect($config)->toBeArray()
        ->and($config['model'])->toBe('sonnet')
        ->and($config['allowed_tools'])->toBe(['tool1'])
        ->and($config['system_prompt'])->toBe('Test prompt')
        ->and($config['max_budget_usd'])->toBe(10.0)
        ->and($config['requires_approval'])->toBeTrue();
});

test('can be created via factory', function () {
    $agent = Agent::factory()->create();

    expect($agent)->toBeInstanceOf(Agent::class)
        ->and($agent->exists)->toBeTrue();
});
