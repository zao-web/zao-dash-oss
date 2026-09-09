<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('command executes agents that are due', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'schedule' => '* * * * *', // Every minute
    ]);

    $this->artisan('agents:run-scheduled')
        ->assertExitCode(0)
        ->assertSuccessful();

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($agent) {
        return $job->agent->id === $agent->id;
    });
});

test('command shows message when no scheduled agents found', function () {
    Agent::factory()->create([
        'status' => 'active',
        'schedule' => null, // No schedule
    ]);

    $this->artisan('agents:run-scheduled')
        ->expectsOutput('No scheduled agents found.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command skips paused agents', function () {
    Agent::factory()->create([
        'status' => 'paused',
        'schedule' => '* * * * *',
    ]);

    $this->artisan('agents:run-scheduled')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command skips agents not due', function () {
    // Schedule for next year
    Agent::factory()->create([
        'status' => 'active',
        'schedule' => '0 0 1 1 * 2099',
    ]);

    $this->artisan('agents:run-scheduled')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command warns on invalid cron expression', function () {
    Agent::factory()->create([
        'status' => 'active',
        'name' => 'Test Agent',
        'schedule' => 'invalid cron',
    ]);

    $this->artisan('agents:run-scheduled')
        ->expectsOutput('Invalid cron for Test Agent: invalid cron')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('dry run mode shows what would run without executing', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'name' => 'Test Agent',
        'schedule' => '* * * * *',
    ]);

    $this->artisan('agents:run-scheduled', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN] Would trigger: Test Agent')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('command prevents duplicate runs in same minute', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'schedule' => '* * * * *',
    ]);

    // Create a recent run
    AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SCHEDULED,
        'created_at' => now(),
    ]);

    $this->artisan('agents:run-scheduled')
        ->expectsOutputToContain('Skipping')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('status option shows all scheduled agents', function () {
    Agent::factory()->create([
        'status' => 'active',
        'name' => 'Test Agent',
        'schedule' => '* * * * *',
    ]);

    $this->artisan('agents:run-scheduled', ['--status' => true])
        ->expectsOutput('Scheduled Agents Status')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

test('status option shows invalid schedules', function () {
    Agent::factory()->create([
        'status' => 'active',
        'name' => 'Bad Agent',
        'schedule' => 'not a cron',
    ]);

    $this->artisan('agents:run-scheduled', ['--status' => true])
        ->expectsOutputToContain('Bad Agent')
        ->assertExitCode(0);
});

test('command handles execution errors gracefully', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'name' => 'Error Agent',
        'schedule' => '* * * * *',
    ]);

    // Force an error by making the agent invalid
    $agent->update(['slug' => null]);

    $this->artisan('agents:run-scheduled')
        ->assertExitCode(0); // Should still complete

    Queue::assertNothingPushed();
});

test('command dispatches job with correct metadata', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'schedule' => '* * * * *',
    ]);

    $this->artisan('agents:run-scheduled')
        ->assertExitCode(0);

    Queue::assertPushed(ExecuteAgentJob::class, function ($job) {
        return $job->invocationSource === AgentRun::SOURCE_SCHEDULED
            && $job->invokedBy === 'scheduler'
            && isset($job->triggerMetadata['cron_expression']);
    });
});

test('command reports number of triggered agents', function () {
    Agent::factory()->count(3)->create([
        'status' => 'active',
        'schedule' => '* * * * *',
    ]);

    $this->artisan('agents:run-scheduled')
        ->expectsOutput('Triggered 3 agent(s).')
        ->assertExitCode(0);

    Queue::assertPushed(ExecuteAgentJob::class, 3);
});

test('command skips agents with null schedule', function () {
    Agent::factory()->create([
        'status' => 'active',
        'schedule' => null,
    ]);

    $this->artisan('agents:run-scheduled')
        ->expectsOutput('No scheduled agents found.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});
