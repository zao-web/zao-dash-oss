<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\Agent;
use App\Services\Agents\ClaudeAgentSdk;

test('command executes SDK test successfully', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => '4'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001234,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutput('Testing Claude Agent SDK...')
        ->expectsOutputToContain('Success')
        ->expectsOutputToContain('Yes')
        ->assertExitCode(0);
});

test('command uses sonnet model by default', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('sonnet')
        ->assertExitCode(0);
});

test('command accepts custom model option', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk', ['--model' => 'opus'])
        ->expectsOutputToContain('opus')
        ->assertExitCode(0);
});

test('command accepts custom prompt', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'Custom response'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->with(
            Mockery::type(Agent::class),
            Mockery::type('App\Models\AgentRun'),
            Mockery::on(fn ($config) => $config['prompt'] === 'What is the meaning of life?')
        )
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk', ['--prompt' => 'What is the meaning of life?'])
        ->assertExitCode(0);
});

test('command enables tools when option provided', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'Found 5 clients'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk', ['--tools' => true])
        ->expectsOutputToContain('Tools Enabled')
        ->expectsOutputToContain('Yes')
        ->assertExitCode(0);
});

test('command disables tools by default', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('Tools Enabled')
        ->expectsOutputToContain('No')
        ->assertExitCode(0);
});

test('command displays execution metrics', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 1234,
        'outputTokens' => 567,
        'costUsd' => 0.056789,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('Input Tokens')
        ->expectsOutputToContain('1,234')
        ->expectsOutputToContain('Output Tokens')
        ->expectsOutputToContain('567')
        ->expectsOutputToContain('Cost')
        ->expectsOutputToContain('$0.056789')
        ->assertExitCode(0);
});

test('command displays response content', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'This is a test response from the SDK'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutput('Response:')
        ->expectsOutputToContain('This is a test response from the SDK')
        ->assertExitCode(0);
});

test('command displays tool results when available', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => [
            'response' => 'Found 3 clients',
            'tool_results' => [
                ['tool' => 'query_database', 'result' => ['count' => 3]],
                ['tool' => 'send_notification', 'result' => ['sent' => true]],
            ],
        ],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk', ['--tools' => true])
        ->expectsOutput('Tool Calls:')
        ->expectsOutputToContain('query_database')
        ->expectsOutputToContain('send_notification')
        ->assertExitCode(0);
});

test('command displays turn count when available', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test', 'turns' => 3],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('Turns')
        ->assertExitCode(0);
});

test('command fails when SDK execution fails', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => false,
        'output' => ['error' => 'API error occurred'],
        'inputTokens' => 100,
        'outputTokens' => 0,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('No')
        ->assertExitCode(1);
});

test('command displays error message on failure', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => false,
        'output' => ['error' => 'Authentication failed'],
        'inputTokens' => 100,
        'outputTokens' => 0,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('Authentication failed')
        ->assertExitCode(1);
});

test('command uses default prompt when none provided', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => '4'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->with(
            Mockery::type(Agent::class),
            Mockery::type('App\Models\AgentRun'),
            Mockery::on(fn ($config) => str_contains($config['prompt'], '2 + 2'))
        )
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->assertExitCode(0);
});

test('command uses database query prompt with tools enabled', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'No clients found'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->with(
            Mockery::type(Agent::class),
            Mockery::type('App\Models\AgentRun'),
            Mockery::on(fn ($config) => str_contains($config['prompt'], 'Query the database'))
        )
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk', ['--tools' => true])
        ->assertExitCode(0);
});

test('command shows execution duration', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->expectsOutputToContain('Duration')
        ->assertExitCode(0);
});

test('command includes test mode context', function () {
    $sdk = Mockery::mock(ClaudeAgentSdk::class);
    $this->app->instance(ClaudeAgentSdk::class, $sdk);

    $result = (object) [
        'success' => true,
        'output' => ['response' => 'test'],
        'inputTokens' => 100,
        'outputTokens' => 20,
        'costUsd' => 0.001,
    ];

    $sdk->shouldReceive('execute')
        ->with(
            Mockery::type(Agent::class),
            Mockery::type('App\Models\AgentRun'),
            Mockery::on(fn ($config) => $config['context']['test_mode'] === true)
        )
        ->once()
        ->andReturn($result);

    $this->artisan('agents:test-sdk')
        ->assertExitCode(0);
});
