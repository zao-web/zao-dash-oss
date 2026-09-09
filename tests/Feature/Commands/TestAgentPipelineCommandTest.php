<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('command runs pipeline test with active agent', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Pipeline test successful!'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', ['--sync' => true])
        ->expectsOutput('Starting agent pipeline test...')
        ->expectsOutputToContain('Pipeline test PASSED')
        ->assertExitCode(0);
});

test('command fails when no active agent found', function () {
    $this->artisan('agents:test-pipeline')
        ->expectsOutput('No active agent found.')
        ->expectsOutput('Run `php artisan agents:sync` to register agents.')
        ->assertExitCode(1);
});

test('command runs specific agent by slug', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create([
        'slug' => 'test-agent',
        'status' => 'active',
    ]);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Pipeline test successful!'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => 'test-agent',
        '--sync' => true,
    ])->assertExitCode(0);
});

test('command runs async by default', function () {
    $agent = Agent::factory()->create(['status' => 'active']);

    $this->artisan('agents:test-pipeline', ['agent' => $agent->slug])
        ->expectsOutputToContain('Async (queued)')
        ->assertExitCode(0);

    Queue::assertPushed(ExecuteAgentJob::class);
});

test('command runs sync when option provided', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Test output'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('Synchronous')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command uses echo scenario by default', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Pipeline test successful!'],
    ]);

    $executor->shouldReceive('execute')
        ->with(
            agent: Mockery::type(Agent::class),
            config: Mockery::on(fn ($c) => str_contains($c['prompt'], 'Pipeline test successful')),
            invocationSource: AgentRun::SOURCE_MANUAL,
            invokedBy: 'test:pipeline'
        )
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])->assertExitCode(0);
});

test('command supports content scenario', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'A case study about a project...'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
        '--scenario' => 'content',
    ])
        ->expectsOutputToContain('content')
        ->assertExitCode(0);
});

test('command supports health scenario', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Health analysis for Test Corp...'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
        '--scenario' => 'health',
    ])->assertExitCode(0);
});

test('command shows run details', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Test'],
        'cost_usd' => 0.05,
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('Run ID')
        ->expectsOutputToContain('Status')
        ->expectsOutputToContain('Cost')
        ->assertExitCode(0);
});

test('command fails when run is not completed', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'failed',
        'output' => null,
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('Pipeline test FAILED')
        ->assertExitCode(1);
});

test('command fails when output is empty', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => null,
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('No output produced')
        ->assertExitCode(1);
});

test('command verifies expected output for echo scenario', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Wrong output'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
        '--scenario' => 'echo',
    ])
        ->expectsOutputToContain('Output did not contain expected content')
        ->assertExitCode(1);
});

test('command handles execution errors gracefully', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $executor->shouldReceive('execute')
        ->once()
        ->andThrow(new \Exception('Test error'));

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutput('Failed to create agent run.')
        ->assertExitCode(1);
});

test('command shows routing results when available', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Test'],
        'metadata' => [
            'routing_results' => [
                'slack' => ['status' => 'success'],
                'email' => ['status' => 'skipped'],
            ],
        ],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('Checking output routing')
        ->assertExitCode(0);
});

test('command shows message when no routing configured', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $this->app->instance(AgentExecutor::class, $executor);

    $agent = Agent::factory()->create(['status' => 'active']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['response' => 'Test'],
    ]);

    $executor->shouldReceive('execute')
        ->once()
        ->andReturn($run);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--sync' => true,
    ])
        ->expectsOutputToContain('No routing configured')
        ->assertExitCode(0);
});

test('command supports custom timeout', function () {
    $agent = Agent::factory()->create(['status' => 'active']);

    $this->artisan('agents:test-pipeline', [
        'agent' => $agent->slug,
        '--timeout' => '120',
    ])->assertExitCode(0);

    Queue::assertPushed(ExecuteAgentJob::class);
});

test('command includes test context in run', function () {
    $agent = Agent::factory()->create(['status' => 'active']);

    $this->artisan('agents:test-pipeline', ['agent' => $agent->slug])
        ->assertExitCode(0);

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) {
        return isset($job->config['context']['test_run'])
            && $job->config['context']['test_run'] === true;
    });
});
