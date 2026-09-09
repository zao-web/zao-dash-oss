<?php

use App\Events\AgentRunCompleted;
use App\Events\NotificationCreated;
use App\Jobs\ExecuteAgentJob;
use App\Jobs\ProcessAgentResultJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Config::set('agents.chains', []);
    Config::set('agents.circuit_breaker.failure_threshold', 3);

    $this->agent = Agent::factory()->create(['slug' => 'test-agent']);
    $this->agentRun = AgentRun::factory()->create([
        'agent_id' => 'test-agent',
        'status' => 'running',
    ]);
});

test('job can be dispatched', function () {
    Queue::fake();

    ProcessAgentResultJob::dispatch($this->agentRun, ['status' => 'completed']);

    Queue::assertPushed(ProcessAgentResultJob::class);
});

test('job has correct queue configuration', function () {
    $job = new ProcessAgentResultJob($this->agentRun, []);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(30);
});

test('handle updates agent run with results', function () {
    $result = [
        'status' => 'completed',
        'output' => ['data' => 'test'],
        'tokens_used' => 1000,
        'cost_usd' => 0.05,
    ];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    $this->agentRun->refresh();
    expect($this->agentRun->status)->toBe('completed')
        ->and($this->agentRun->output)->toBe(['data' => 'test'])
        ->and($this->agentRun->tokens_used)->toBe(1000)
        ->and($this->agentRun->cost_usd)->toBe(0.05)
        ->and($this->agentRun->completed_at)->not->toBeNull();
});

test('handle processes success result', function () {
    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Event::assertDispatched(AgentRunCompleted::class);
    Event::assertDispatched(NotificationCreated::class);
});

test('handle creates tasks from result', function () {
    $result = [
        'status' => 'completed',
        'tasks' => [
            ['description' => 'Task 1', 'priority' => 'high'],
            ['description' => 'Task 2', 'priority' => 'medium'],
        ],
    ];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    expect(AgentTask::count())->toBe(2);

    $task = AgentTask::first();
    expect($task->task_description)->toBe('Task 1')
        ->and($task->priority)->toBe('high')
        ->and($task->agent_run_id)->toBe($this->agentRun->id);
});

test('handle resets circuit breaker on success', function () {
    $this->agent->update([
        'circuit_broken_at' => now(),
        'consecutive_failures' => 5,
    ]);

    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    $this->agent->refresh();
    expect($this->agent->circuit_broken_at)->toBeNull()
        ->and($this->agent->consecutive_failures)->toBe(0);
});

test('handle creates approval requests when required', function () {
    $result = [
        'status' => 'requires_approval',
        'approvals' => [
            [
                'category' => 'financial',
                'title' => 'Approve expense',
                'description' => 'Need approval for $500 expense',
                'risk_level' => 'high',
            ],
        ],
    ];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    expect(ApprovalRequest::count())->toBe(1);

    $approval = ApprovalRequest::first();
    expect($approval->category)->toBe('financial')
        ->and($approval->title)->toBe('Approve expense')
        ->and($approval->risk_level)->toBe('high')
        ->and($approval->status)->toBe('pending');
});

test('handle increments circuit breaker on failure', function () {
    $result = ['status' => 'failed', 'error' => 'Test error'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    $this->agent->refresh();
    expect($this->agent->consecutive_failures)->toBe(1);
});

test('handle triggers circuit breaker after threshold', function () {
    $this->agent->update(['consecutive_failures' => 2]);

    $result = ['status' => 'failed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    $this->agent->refresh();
    expect($this->agent->consecutive_failures)->toBe(3)
        ->and($this->agent->circuit_broken_at)->not->toBeNull();
});

test('handle dispatches chained agents', function () {
    Queue::fake();

    $chainedAgent = Agent::factory()->create(['slug' => 'chained-agent', 'is_active' => true]);

    Config::set('agents.chains', [
        'test-agent' => ['chained-agent'],
    ]);

    $result = ['status' => 'completed', 'output' => ['data' => 'test']];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($chainedAgent) {
        return $job->agent->id === $chainedAgent->id
            && $job->config['chained_from'] === 'test-agent'
            && $job->config['parent_output'] === ['data' => 'test'];
    });
});

test('handle skips inactive chained agents', function () {
    Queue::fake();
    Log::spy();

    $inactiveAgent = Agent::factory()->create(['slug' => 'inactive-agent', 'is_active' => false]);

    Config::set('agents.chains', [
        'test-agent' => ['inactive-agent'],
    ]);

    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Queue::assertNothingPushed();

    Log::shouldHaveReceived('info')
        ->with(Mockery::pattern('/inactive or not found/'), Mockery::any());
});

test('handle skips chained agents with broken circuit', function () {
    Queue::fake();
    Log::spy();

    $brokenAgent = Agent::factory()->create([
        'slug' => 'broken-agent',
        'is_active' => true,
        'circuit_broken_at' => now(),
    ]);

    Config::set('agents.chains', [
        'test-agent' => ['broken-agent'],
    ]);

    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Queue::assertNothingPushed();

    Log::shouldHaveReceived('info')
        ->with(Mockery::pattern('/circuit broken/'), Mockery::any());
});

test('handle dispatches completion event', function () {
    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Event::assertDispatched(AgentRunCompleted::class, function ($event) {
        return $event->agentRun->id === $this->agentRun->id;
    });
});

test('handle sends notification on completion', function () {
    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Event::assertDispatched(NotificationCreated::class);
});

test('handle sends notification on approval required', function () {
    $result = [
        'status' => 'requires_approval',
        'approvals' => [
            ['title' => 'Test'],
        ],
    ];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Event::assertDispatched(NotificationCreated::class);
});

test('handle sends notification on failure', function () {
    $result = ['status' => 'failed', 'error' => 'Test error'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Event::assertDispatched(NotificationCreated::class);
});

test('handle logs processing', function () {
    Log::spy();

    $result = ['status' => 'completed'];

    $job = new ProcessAgentResultJob($this->agentRun, $result);
    $job->handle();

    Log::shouldHaveReceived('info')
        ->with('Processing agent result', Mockery::on(fn ($ctx) => $ctx['run_id'] === $this->agentRun->id
        ));
});

test('handle catches and logs errors', function () {
    Log::spy();

    // Force an error by passing invalid result
    $result = ['status' => 'completed'];

    // Mock the agentRun to throw on update
    $agentRun = Mockery::mock(AgentRun::class)->makePartial();
    $agentRun->shouldReceive('update')->andThrow(new Exception('Database error'));
    $agentRun->id = 123;
    $agentRun->agent_id = 'test';

    $job = new ProcessAgentResultJob($agentRun, $result);

    expect(fn () => $job->handle())->toThrow(Exception::class);

    Log::shouldHaveReceived('error')
        ->with('Error processing agent result', Mockery::any());
});

test('failed method marks run as failed', function () {
    $result = ['status' => 'completed'];
    $job = new ProcessAgentResultJob($this->agentRun, $result);

    $exception = new Exception('Job failed');
    $job->failed($exception);

    $this->agentRun->refresh();
    expect($this->agentRun->status)->toBe('failed')
        ->and($this->agentRun->error_message)->toContain('Result processing failed');
});

test('failed method logs error', function () {
    Log::spy();

    $result = ['status' => 'completed'];
    $job = new ProcessAgentResultJob($this->agentRun, $result);

    $exception = new Exception('Job failed');
    $job->failed($exception);

    Log::shouldHaveReceived('error')
        ->with('ProcessAgentResultJob failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new ProcessAgentResultJob($this->agentRun, []);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(ProcessAgentResultJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
