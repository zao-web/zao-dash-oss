<?php

use App\Jobs\RunAgentJob;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\InteractiveClaudeRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('run agent job skips cancelled runs before execution starts', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_CANCELLED,
        'completed_at' => now(),
    ]);

    $executor = Mockery::mock(AgentExecutor::class);
    $executor->shouldNotReceive('executePendingRun');

    $job = new RunAgentJob($run);
    $job->handle($executor);

    expect($run->fresh()->status)->toBe(AgentRun::STATUS_CANCELLED);
});

test('run interactive agent job skips cancelled runs before execution starts', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_CANCELLED,
        'completed_at' => now(),
    ]);

    $runner = Mockery::mock(InteractiveClaudeRunner::class);
    $runner->shouldNotReceive('execute');
    $runner->shouldNotReceive('resume');

    $job = new RunInteractiveAgentJob($run);
    $job->handle($runner);

    expect($run->fresh()->status)->toBe(AgentRun::STATUS_CANCELLED);
});
