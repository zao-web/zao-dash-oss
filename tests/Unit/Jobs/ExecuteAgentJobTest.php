<?php

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->agent = Agent::factory()->create([
        'slug' => 'test-agent',
        'status' => 'active',
    ]);
});

test('job can be dispatched', function () {
    Queue::fake();

    ExecuteAgentJob::dispatch($this->agent);

    Queue::assertPushed(ExecuteAgentJob::class);
});

test('job dispatches with correct properties', function () {
    Queue::fake();

    $config = ['test' => 'value'];
    $source = AgentRun::SOURCE_WEBHOOK;
    $invokedBy = 'user-123';
    $metadata = ['key' => 'value'];

    ExecuteAgentJob::dispatch(
        agent: $this->agent,
        config: $config,
        invocationSource: $source,
        invokedBy: $invokedBy,
        triggerMetadata: $metadata
    );

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($config, $source, $invokedBy, $metadata) {
        return $job->agent->id === $this->agent->id
            && $job->config === $config
            && $job->invocationSource === $source
            && $job->invokedBy === $invokedBy
            && $job->triggerMetadata === $metadata;
    });
});

test('handle method executes agent', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $config = ['prompt' => 'test'];

    $executor->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($agent) => $agent->id === $this->agent->id),
            $config,
            AgentRun::SOURCE_MANUAL,
            null,
            []
        );

    $job = new ExecuteAgentJob($this->agent, $config);
    $job->handle($executor);
});

test('handle method passes all parameters to executor', function () {
    $executor = Mockery::mock(AgentExecutor::class);
    $config = ['test' => 'config'];
    $source = AgentRun::SOURCE_SCHEDULED;
    $invokedBy = 'scheduler';
    $metadata = ['cron' => '0 * * * *'];

    $executor->shouldReceive('execute')
        ->once()
        ->with(
            Mockery::on(fn ($agent) => $agent->id === $this->agent->id),
            $config,
            $source,
            $invokedBy,
            $metadata
        );

    $job = new ExecuteAgentJob($this->agent, $config, $source, $invokedBy, $metadata);
    $job->handle($executor);
});

test('job has correct queue configuration', function () {
    $job = new ExecuteAgentJob($this->agent);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(60)
        ->and($job->timeout)->toBe(1800);
});

test('uniqueId returns agent-specific identifier', function () {
    $job = new ExecuteAgentJob($this->agent);

    expect($job->uniqueId())->toBe('agent-'.$this->agent->id);
});

test('tags include agent slug and source', function () {
    $job = new ExecuteAgentJob(
        agent: $this->agent,
        invocationSource: AgentRun::SOURCE_WEBHOOK
    );

    $tags = $job->tags();

    expect($tags)->toContain('agent:test-agent')
        ->and($tags)->toContain('source:'.AgentRun::SOURCE_WEBHOOK);
});

test('failed method handles exception gracefully', function () {
    $job = new ExecuteAgentJob($this->agent);
    $exception = new Exception('Test error');

    // Should not throw
    expect(fn () => $job->failed($exception))->not->toThrow(Exception::class);
});

test('job implements ShouldQueue interface', function () {
    $job = new ExecuteAgentJob($this->agent);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(ExecuteAgentJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});

test('default values are set correctly', function () {
    $job = new ExecuteAgentJob($this->agent);

    expect($job->config)->toBe([])
        ->and($job->invocationSource)->toBe(AgentRun::SOURCE_MANUAL)
        ->and($job->invokedBy)->toBeNull()
        ->and($job->triggerMetadata)->toBe([]);
});
