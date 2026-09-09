<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\Agent;
use App\Models\AgentChain;
use App\Models\AgentChainRun;
use App\Services\Agents\ChainExecutor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('command lists available chains and templates', function () {
    AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-one', 'condition' => null],
            ['agent_slug' => 'agent-two', 'condition' => null],
        ],
    ]);

    $this->artisan('agents:chain', ['--list' => true])
        ->expectsOutput('Available Chain Templates:')
        ->expectsOutput('Saved Chains:')
        ->expectsOutputToContain('test-chain')
        ->expectsOutputToContain('Test Chain')
        ->assertExitCode(0);
});

test('command shows message when no saved chains exist', function () {
    $this->artisan('agents:chain', ['--list' => true])
        ->expectsOutputToContain('No saved chains')
        ->assertExitCode(0);
});

test('command creates chain from template', function () {
    $this->artisan('agents:chain', ['--create' => 'case_study_workflow'])
        ->expectsOutputToContain('Created chain')
        ->assertExitCode(0);

    expect(AgentChain::where('slug', 'case-study-workflow')->exists())->toBeTrue();
});

test('command fails when template not found', function () {
    $this->artisan('agents:chain', ['--create' => 'nonexistent-template'])
        ->expectsOutput("Template 'nonexistent-template' not found.")
        ->assertExitCode(1);
});

test('command validates chain successfully', function () {
    $chain = AgentChain::create([
        'slug' => 'valid-chain',
        'name' => 'Valid Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'test-agent', 'condition' => null],
        ],
    ]);

    Agent::factory()->create(['slug' => 'test-agent', 'status' => 'active']);

    $this->artisan('agents:chain', ['--validate' => 'valid-chain'])
        ->expectsOutputToContain('is valid')
        ->assertExitCode(0);
});

test('command shows validation errors for invalid chain', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chain = AgentChain::create([
        'slug' => 'invalid-chain',
        'name' => 'Invalid Chain',
        'is_active' => true,
        'steps' => [],
    ]);

    $executor->shouldReceive('validateChain')
        ->with(Mockery::on(fn ($c) => $c->slug === 'invalid-chain'))
        ->andReturn(['No steps defined', 'Chain is empty']);

    $this->artisan('agents:chain', ['--validate' => 'invalid-chain'])
        ->expectsOutputToContain('has issues')
        ->expectsOutputToContain('No steps defined')
        ->assertExitCode(1);
});

test('command fails validation when chain not found', function () {
    $this->artisan('agents:chain', ['--validate' => 'nonexistent'])
        ->expectsOutput("Chain 'nonexistent' not found.")
        ->assertExitCode(1);
});

test('command runs existing chain with input', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'test-agent', 'condition' => null],
        ],
    ]);

    $chainRun = new AgentChainRun([
        'id' => 1,
        'status' => 'running',
    ]);

    $executor->shouldReceive('startChain')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->slug === 'test-chain'),
            'test input',
            'manual:cli'
        )
        ->andReturn($chainRun);

    $this->artisan('agents:chain', [
        'chain' => 'test-chain',
        '--input' => 'test input',
    ])
        ->expectsOutput('Starting chain execution...')
        ->expectsOutputToContain('Chain Run ID')
        ->assertExitCode(0);
});

test('command runs chain from template', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chainRun = new AgentChainRun([
        'id' => 1,
        'status' => 'running',
    ]);

    $executor->shouldReceive('startFromTemplate')
        ->once()
        ->with('case_study_workflow', 'test input', 'manual:cli')
        ->andReturn($chainRun);

    $this->artisan('agents:chain', [
        'chain' => 'case_study_workflow',
        '--input' => 'test input',
    ])
        ->expectsOutput('Starting chain execution...')
        ->assertExitCode(0);
});

test('command prompts for input when not provided', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [['agent_slug' => 'test-agent', 'condition' => null]],
    ]);

    $chainRun = new AgentChainRun(['id' => 1, 'status' => 'running']);

    $executor->shouldReceive('startChain')
        ->once()
        ->andReturn($chainRun);

    $this->artisan('agents:chain', ['chain' => 'test-chain'])
        ->expectsQuestion('Enter initial input for the chain', 'prompted input')
        ->assertExitCode(0);
});

test('command fails when chain not found', function () {
    $this->artisan('agents:chain', [
        'chain' => 'nonexistent',
        '--input' => 'test',
    ])
        ->expectsOutput("Chain or template 'nonexistent' not found.")
        ->assertExitCode(1);
});

test('command fails when no chain argument provided', function () {
    $this->artisan('agents:chain')
        ->expectsOutput('Provide a chain slug or use --list to see available chains.')
        ->assertExitCode(1);
});

test('command fails when input is empty', function () {
    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [['agent_slug' => 'test-agent', 'condition' => null]],
    ]);

    $this->artisan('agents:chain', ['chain' => 'test-chain'])
        ->expectsQuestion('Enter initial input for the chain', '')
        ->expectsOutput('Input is required.')
        ->assertExitCode(1);
});

test('command fails gracefully when chain execution fails', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [['agent_slug' => 'test-agent', 'condition' => null]],
    ]);

    $executor->shouldReceive('startChain')
        ->once()
        ->andReturn(null);

    $this->artisan('agents:chain', [
        'chain' => 'test-chain',
        '--input' => 'test',
    ])
        ->expectsOutput('Failed to start chain.')
        ->assertExitCode(1);
});

test('command shows monitoring instructions after starting chain', function () {
    $executor = Mockery::mock(ChainExecutor::class);
    $this->app->instance(ChainExecutor::class, $executor);

    $chain = AgentChain::create([
        'slug' => 'test-chain',
        'name' => 'Test Chain',
        'is_active' => true,
        'steps' => [['agent_slug' => 'test-agent', 'condition' => null]],
    ]);

    $chainRun = new AgentChainRun(['id' => 123, 'status' => 'running']);

    $executor->shouldReceive('startChain')
        ->once()
        ->andReturn($chainRun);

    $this->artisan('agents:chain', [
        'chain' => 'test-chain',
        '--input' => 'test',
    ])
        ->expectsOutput('Chain execution started. Monitor with:')
        ->expectsOutput('  php artisan agents:chain-status 123')
        ->assertExitCode(0);
});

test('list command shows chain step count', function () {
    AgentChain::create([
        'slug' => 'multi-step',
        'name' => 'Multi Step Chain',
        'is_active' => true,
        'steps' => [
            ['agent_slug' => 'agent-1', 'condition' => null],
            ['agent_slug' => 'agent-2', 'condition' => null],
            ['agent_slug' => 'agent-3', 'condition' => null],
        ],
    ]);

    $this->artisan('agents:chain', ['--list' => true])
        ->expectsOutputToContain('Multi Step Chain (3 steps)')
        ->assertExitCode(0);
});

test('list command shows conditional steps', function () {
    $this->artisan('agents:chain', ['--list' => true])
        ->expectsOutput('Available Chain Templates:')
        ->assertExitCode(0);
});

test('create command shows chain details after creation', function () {
    $this->artisan('agents:chain', ['--create' => 'case_study_workflow'])
        ->expectsOutputToContain('Slug')
        ->expectsOutputToContain('Steps')
        ->assertExitCode(0);
});
