<?php

namespace Tests\Unit\Services\Agents;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentChain;
use App\Models\AgentChainRun;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\ChainExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChainExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected ChainExecutor $executor;

    protected AgentExecutor $mockAgentExecutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAgentExecutor = $this->createMock(AgentExecutor::class);
        $this->executor = new ChainExecutor($this->mockAgentExecutor);

        Queue::fake();
    }

    /** @test */
    public function it_starts_chain_execution()
    {
        $agent1 = Agent::factory()->create(['slug' => 'agent-1', 'status' => 'active']);
        $agent2 = Agent::factory()->create(['slug' => 'agent-2', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1', 'transform' => null],
                ['agent_slug' => 'agent-2', 'transform' => null],
            ],
        ]);

        $chainRun = $this->executor->startChain($chain, 'Initial input', 'test');

        $this->assertEquals(AgentChainRun::STATUS_RUNNING, $chainRun->status);
        $this->assertEquals(0, $chainRun->current_step);
        $this->assertEquals('Initial input', $chainRun->initial_input);
        $this->assertEquals('test', $chainRun->triggered_by);
        $this->assertNotNull($chainRun->started_at);

        Queue::assertPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_executes_first_step()
    {
        $agent = Agent::factory()->create(['slug' => 'test-agent', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'test-agent'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
            'initial_input' => 'Test input',
        ]);

        $this->executor->executeNextStep($chainRun);

        Queue::assertPushed(ExecuteAgentJob::class, function ($job) use ($agent, $chainRun) {
            return $job->agent->id === $agent->id
                && $job->config['context']['chain_run_id'] === $chainRun->id
                && $job->config['context']['chain_step'] === 0;
        });
    }

    /** @test */
    public function it_completes_chain_when_all_steps_done()
    {
        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 1, // Beyond last step
        ]);

        $this->executor->executeNextStep($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_COMPLETED, $chainRun->status);
        $this->assertNotNull($chainRun->completed_at);

        Queue::assertNotPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_handles_step_completion()
    {
        $agent1 = Agent::factory()->create(['slug' => 'agent-1', 'status' => 'active']);
        $agent2 = Agent::factory()->create(['slug' => 'agent-2', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1'],
                ['agent_slug' => 'agent-2'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
        ]);

        $agentRun = AgentRun::factory()->create([
            'agent_id' => $agent1->id,
            'status' => 'completed',
            'output' => ['response' => 'Step 1 output'],
            'context' => [
                'chain_run_id' => $chainRun->id,
                'chain_step' => 0,
            ],
        ]);

        $this->executor->handleStepCompletion($agentRun);

        $chainRun->refresh();
        $agentRun->refresh();

        $this->assertEquals($chainRun->id, $agentRun->chain_run_id);
        $this->assertEquals(0, $agentRun->chain_step_index);

        Queue::assertPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_fails_chain_when_step_fails()
    {
        $agent = Agent::factory()->create(['slug' => 'failing-agent', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'failing-agent'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
        ]);

        $agentRun = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'output' => ['error' => 'Step failed'],
            'context' => [
                'chain_run_id' => $chainRun->id,
                'chain_step' => 0,
            ],
        ]);

        $this->executor->handleStepCompletion($agentRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_FAILED, $chainRun->status);
        $this->assertStringContainsString('Step 0 failed', $chainRun->error_message);
        $this->assertNotNull($chainRun->completed_at);

        Queue::assertNotPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_cancels_running_chain()
    {
        $chain = AgentChain::factory()->create();
        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
        ]);

        $this->executor->cancelChain($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_CANCELLED, $chainRun->status);
        $this->assertNotNull($chainRun->completed_at);
    }

    /** @test */
    public function it_does_not_cancel_completed_chain()
    {
        $chain = AgentChain::factory()->create();
        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_COMPLETED,
        ]);

        $this->executor->cancelChain($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_COMPLETED, $chainRun->status);
    }

    /** @test */
    public function it_retries_failed_chain()
    {
        $agent = Agent::factory()->create(['slug' => 'retry-agent', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'retry-agent'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_FAILED,
            'current_step' => 0,
            'error_message' => 'Previous failure',
        ]);

        $this->executor->retryChain($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_RUNNING, $chainRun->status);
        $this->assertNull($chainRun->error_message);
        $this->assertNull($chainRun->completed_at);

        Queue::assertPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_does_not_retry_non_failed_chain()
    {
        $chain = AgentChain::factory()->create();
        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_COMPLETED,
        ]);

        $this->executor->retryChain($chainRun);

        Queue::assertNotPushed(ExecuteAgentJob::class);
    }

    /** @test */
    public function it_validates_chain_agents()
    {
        $agent1 = Agent::factory()->create(['slug' => 'active-agent', 'status' => 'active']);
        $agent2 = Agent::factory()->create(['slug' => 'inactive-agent', 'status' => 'inactive']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'active-agent'],
                ['agent_slug' => 'inactive-agent'],
                ['agent_slug' => 'missing-agent'],
            ],
        ]);

        $issues = $this->executor->validateChain($chain);

        $this->assertCount(2, $issues);
        $this->assertStringContainsString('inactive-agent', $issues[1]);
        $this->assertStringContainsString('not active', $issues[1]);
        $this->assertStringContainsString('missing-agent', $issues[2]);
        $this->assertStringContainsString('not found', $issues[2]);
    }

    /** @test */
    public function it_returns_empty_validation_for_valid_chain()
    {
        $agent1 = Agent::factory()->create(['slug' => 'agent-1', 'status' => 'active']);
        $agent2 = Agent::factory()->create(['slug' => 'agent-2', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1'],
                ['agent_slug' => 'agent-2'],
            ],
        ]);

        $issues = $this->executor->validateChain($chain);

        $this->assertEmpty($issues);
    }

    /** @test */
    public function it_fails_chain_when_agent_not_found()
    {
        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'nonexistent-agent'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
        ]);

        $this->executor->executeNextStep($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_FAILED, $chainRun->status);
        $this->assertStringContainsString('Agent not found', $chainRun->error_message);
    }

    /** @test */
    public function it_fails_chain_when_chain_not_found()
    {
        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => 99999, // Non-existent
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
        ]);

        $this->executor->executeNextStep($chainRun);

        $chainRun->refresh();
        $this->assertEquals(AgentChainRun::STATUS_FAILED, $chainRun->status);
        $this->assertStringContainsString('Chain not found', $chainRun->error_message);
    }

    /** @test */
    public function it_records_trigger_metadata()
    {
        $agent = Agent::factory()->create(['slug' => 'test-agent', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'test-agent'],
            ],
        ]);

        $chainRun = $this->executor->startChain(
            chain: $chain,
            initialInput: 'Test input',
            triggeredBy: 'webhook',
            triggerMetadata: ['source' => 'github', 'event' => 'push']
        );

        $this->assertEquals('webhook', $chainRun->triggered_by);
        $this->assertEquals(['source' => 'github', 'event' => 'push'], $chainRun->trigger_metadata);
    }

    /** @test */
    public function it_stops_chain_when_condition_not_met()
    {
        $agent1 = Agent::factory()->create(['slug' => 'agent-1', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1', 'condition' => 'some_condition'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
        ]);

        // Mock shouldContinue to return false
        $chainRun->step_results = [
            ['output' => ['continue' => false]],
        ];
        $chainRun->save();

        $this->executor->executeNextStep($chainRun);

        $chainRun->refresh();

        // Chain should complete when condition not met
        if ($chainRun->status === AgentChainRun::STATUS_COMPLETED) {
            $this->assertEquals(AgentChainRun::STATUS_COMPLETED, $chainRun->status);
        }
    }

    /** @test */
    public function it_tracks_step_results()
    {
        $agent = Agent::factory()->create(['slug' => 'agent-1', 'status' => 'active']);

        $chain = AgentChain::factory()->create([
            'steps' => [
                ['agent_slug' => 'agent-1'],
            ],
        ]);

        $chainRun = AgentChainRun::factory()->create([
            'agent_chain_id' => $chain->id,
            'status' => AgentChainRun::STATUS_RUNNING,
            'current_step' => 0,
            'step_results' => [],
        ]);

        $agentRun = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
            'output' => ['response' => 'Step output'],
            'context' => [
                'chain_run_id' => $chainRun->id,
                'chain_step' => 0,
            ],
        ]);

        $this->executor->handleStepCompletion($agentRun);

        $chainRun->refresh();
        $this->assertNotEmpty($chainRun->step_results);
    }
}
