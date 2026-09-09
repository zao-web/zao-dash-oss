<?php

use App\Agents\Tools\TriggerAgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockExecutor = Mockery::mock(AgentExecutor::class);
    $this->tool = new TriggerAgentTool($this->mockExecutor);
});

test('getName returns correct name', function () {
    expect($this->tool->name())->toBe('Trigger Agent');
});

test('getDescription returns correct description', function () {
    expect($this->tool->description())->toContain('Trigger an AI agent');
});

test('requiresApproval returns true', function () {
    expect($this->tool->requiresApproval())->toBeTrue();
});

test('riskLevel returns high', function () {
    expect($this->tool->riskLevel())->toBe('high');
});

test('getParameters requires agent_slug', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['required'])->toContain('agent_slug')
        ->and($schema['properties'])->toHaveKey('agent_slug');
});

test('getParameters includes prompt and context', function () {
    $schema = $this->tool->inputSchema();

    expect($schema['properties'])->toHaveKeys(['agent_slug', 'prompt', 'context']);
});

test('validate rejects missing agent_slug', function () {
    $this->tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate rejects non-existent agent', function () {
    $this->tool->validate(['agent_slug' => 'non-existent']);
})->throws(InvalidArgumentException::class);

test('validate accepts valid agent_slug', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $validated = $this->tool->validate([
        'agent_slug' => 'test-agent',
    ]);

    expect($validated)->toHaveKey('agent_slug');
});

test('execute triggers agent execution', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent', 'name' => 'Test Agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id, 'status' => 'running']);

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($passedAgent, $config, $source, $invokedBy) use ($agent) {
            return $passedAgent->id === $agent->id
                && $source === AgentRun::SOURCE_API
                && $invokedBy === 'tool:trigger-agent';
        })
        ->andReturn($run);

    $result = $this->tool->execute(['agent_slug' => 'test-agent']);

    expect($result)->toHaveKeys(['triggered', 'agent', 'run_id', 'status'])
        ->and($result['triggered'])->toBeTrue()
        ->and($result['agent'])->toBe('Test Agent')
        ->and($result['run_id'])->toBe($run->id);
});

test('execute passes prompt to executor', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($passedAgent, $config) {
            return $config['prompt'] === 'Custom prompt';
        })
        ->andReturn($run);

    $this->tool->execute([
        'agent_slug' => 'test-agent',
        'prompt' => 'Custom prompt',
    ]);
});

test('execute passes context to executor', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    $context = ['key' => 'value', 'data' => 123];

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($passedAgent, $config) use ($context) {
            return $config['context'] === $context;
        })
        ->andReturn($run);

    $this->tool->execute([
        'agent_slug' => 'test-agent',
        'context' => $context,
    ]);
});

test('execute defaults to empty prompt', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($passedAgent, $config) {
            return $config['prompt'] === '';
        })
        ->andReturn($run);

    $this->tool->execute(['agent_slug' => 'test-agent']);
});

test('execute defaults to empty context', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($passedAgent, $config) {
            return $config['context'] === [];
        })
        ->andReturn($run);

    $this->tool->execute(['agent_slug' => 'test-agent']);
});

test('execute sets requires_approval flag when run pending', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'pending_approval',
    ]);

    $this->mockExecutor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $result = $this->tool->execute(['agent_slug' => 'test-agent']);

    expect($result['requires_approval'])->toBeTrue();
});

test('execute throws exception for non-existent agent', function () {
    $result = $this->tool->execute(['agent_slug' => 'non-existent']);
})->throws(InvalidArgumentException::class, 'Agent not found');

test('validate rejects prompt over max length', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $this->tool->validate([
        'agent_slug' => 'test-agent',
        'prompt' => str_repeat('a', 5001),
    ]);
})->throws(InvalidArgumentException::class);

test('validate accepts context as array', function () {
    $agent = Agent::factory()->create(['slug' => 'test-agent']);

    $validated = $this->tool->validate([
        'agent_slug' => 'test-agent',
        'context' => ['key' => 'value'],
    ]);

    expect($validated)->toHaveKey('context')
        ->and($validated['context'])->toBeArray();
});
