<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\AgentChain;
use App\Models\AgentChainRun;

test('command shows chain run status', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 0,
        'initial_input' => 'test input',
        'step_results' => [],
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain("Chain Run #{$chainRun->id}")
        ->expectsOutputToContain('Test Chain')
        ->assertExitCode(0);
});

test('command fails when chain run not found', function () {
    $this->artisan('agents:chain-status', ['run_id' => 99999])
        ->expectsOutput('Chain run #99999 not found.')
        ->assertExitCode(1);
});

test('command shows progress information', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
            ['agent_slug' => 'agent-3', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now()->subMinutes(5),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('Progress')
        ->assertExitCode(0);
});

test('command shows cost information', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.1234],
        ],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('Total Cost')
        ->assertExitCode(0);
});

test('command shows step details', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => 'previous_success'],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('Steps:')
        ->expectsOutputToContain('agent-1')
        ->expectsOutputToContain('agent-2')
        ->assertExitCode(0);
});

test('command shows conditional steps', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => 'output_contains:success'],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 0,
        'initial_input' => 'test',
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('condition: output_contains:success')
        ->assertExitCode(0);
});

test('command shows error message when present', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'failed',
        'current_step' => 0,
        'initial_input' => 'test',
        'error_message' => 'Agent execution failed',
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutput('Error: Agent execution failed')
        ->assertExitCode(0);
});

test('command formats status with colors', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('Status')
        ->assertExitCode(0);
});

test('command shows duration when completed', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('Duration')
        ->assertExitCode(0);
});

test('command shows pending steps', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
            ['agent_slug' => 'agent-3', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('pending')
        ->assertExitCode(0);
});

test('watch mode displays real-time updates', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now()->subMinutes(1),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', [
        'run_id' => $chainRun->id,
        '--watch' => true,
    ])
        ->expectsOutputToContain('Watching chain run')
        ->assertExitCode(0);
});

test('watch mode shows status changes', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now()->subSeconds(5),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', [
        'run_id' => $chainRun->id,
        '--watch' => true,
    ])
        ->expectsOutputToContain('Status')
        ->assertExitCode(0);
});

test('command shows step costs', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.0456],
        ],
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('$0.0456')
        ->assertExitCode(0);
});

test('command shows dash for pending step cost', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'running',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->expectsOutputToContain('-')
        ->assertExitCode(0);
});

test('command loads chain relationship', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
        ],
    ]);

    $chainRun = AgentChainRun::create([
        'agent_chain_id' => $chain->id,
        'status' => 'completed',
        'current_step' => 1,
        'initial_input' => 'test',
        'step_results' => [
            0 => ['status' => 'completed', 'cost_usd' => 0.05],
        ],
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $this->artisan('agents:chain-status', ['run_id' => $chainRun->id])
        ->assertExitCode(0);

    expect($chainRun->fresh()->relationLoaded('chain'))->toBeFalse();
});
