<?php

namespace Tests\Unit\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Services\AgentOutputRouter;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\ClaudeAgentSdk;
use App\Services\Agents\ClaudeCliRunner;
use App\Services\Agents\ExecutionResult;
use App\Services\AI\MultiModelConsortium;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected AgentExecutor $executor;

    protected ClaudeCliRunner $mockRunner;

    protected ClaudeAgentSdk $mockSdk;

    protected MultiModelConsortium $mockConsortium;

    protected AgentOutputRouter $mockRouter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRunner = $this->createMock(ClaudeCliRunner::class);
        $this->mockSdk = $this->createMock(ClaudeAgentSdk::class);
        $this->mockConsortium = $this->createMock(MultiModelConsortium::class);
        $this->mockRouter = $this->createMock(AgentOutputRouter::class);

        $this->executor = new AgentExecutor(
            runner: $this->mockRunner,
            sdk: $this->mockSdk,
            consortium: $this->mockConsortium,
            outputRouter: $this->mockRouter
        );
    }

    /** @test */
    public function it_validates_agent_before_execution()
    {
        $agent = Agent::factory()->create(['status' => 'inactive']);

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('must be \'active\'', $run->output['error']);
    }

    /** @test */
    public function it_prevents_execution_when_circuit_breaker_active()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'circuit_broken_at' => now(),
        ]);

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('circuit breaker', $run->output['error']);
    }

    /** @test */
    public function it_creates_approval_request_when_required()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'config' => ['requires_approval' => true],
        ]);

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_PENDING_APPROVAL, $run->status);
        $this->assertDatabaseHas('approval_requests', [
            'agent_run_id' => $run->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_executes_agent_immediately_without_approval()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'config' => ['requires_approval' => false],
        ]);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Test output'],
            rawOutput: 'Test output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 1.5,
            tokensUsed: 100,
            costUsd: 0.001,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $run = $this->executor->execute($agent, ['prompt' => 'Test prompt']);

        $this->assertEquals(AgentExecutor::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->completed_at);
    }

    /** @test */
    public function it_executes_approved_run()
    {
        $agent = Agent::factory()->create(['status' => 'active']);
        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => AgentExecutor::STATUS_PENDING_APPROVAL,
        ]);

        $approval = ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'approved',
        ]);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Approved output'],
            rawOutput: 'Approved output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 2.0,
            tokensUsed: 150,
            costUsd: 0.002,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $result = $this->executor->executeApproved($run);

        $this->assertEquals(AgentExecutor::STATUS_COMPLETED, $result->status);
    }

    /** @test */
    public function it_rejects_execution_if_not_pending_approval()
    {
        $agent = Agent::factory()->create(['status' => 'active']);
        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => AgentExecutor::STATUS_COMPLETED,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not pending approval');

        $this->executor->executeApproved($run);
    }

    /** @test */
    public function it_uses_sdk_when_agent_has_tools()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'execution_mode' => 'sdk',
            'tools' => ['web_search', 'query_database'],
        ]);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'SDK output'],
            rawOutput: 'SDK output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 3.0,
            tokensUsed: 200,
            costUsd: 0.003,
            workspace: null
        );

        $this->mockSdk->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $this->mockRunner->expects($this->never())
            ->method('execute');

        $run = $this->executor->execute($agent, ['prompt' => 'Test with SDK']);

        $this->assertEquals(AgentExecutor::STATUS_COMPLETED, $run->status);
    }

    /** @test */
    public function it_uses_cli_when_execution_mode_is_cli()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'execution_mode' => 'cli',
        ]);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'CLI output'],
            rawOutput: 'CLI output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 2.5,
            tokensUsed: 120,
            costUsd: 0.0015,
            workspace: '/tmp/workspace'
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $this->mockSdk->expects($this->never())
            ->method('execute');

        $run = $this->executor->execute($agent, ['prompt' => 'Test with CLI']);

        $this->assertEquals(AgentExecutor::STATUS_COMPLETED, $run->status);
    }

    /** @test */
    public function it_tracks_cost_and_tokens()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Test'],
            rawOutput: 'Test',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 1.0,
            tokensUsed: 250,
            costUsd: 0.005,
            workspace: null,
            inputTokens: 100,
            outputTokens: 150
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $run = $this->executor->execute($agent);

        $this->assertEquals(0.005, $run->cost_usd);
        $this->assertEquals(100, $run->input_tokens);
        $this->assertEquals(150, $run->output_tokens);
    }

    /** @test */
    public function it_handles_execution_failure()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $failureResult = new ExecutionResult(
            success: false,
            output: ['error' => 'API timeout'],
            rawOutput: '',
            errorOutput: 'API timeout',
            exitCode: 1,
            durationSeconds: 5.0,
            tokensUsed: 0,
            costUsd: 0.0,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($failureResult);

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertEquals('API timeout', $run->output['error']);
    }

    /** @test */
    public function it_catches_exceptions_during_execution()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willThrowException(new \Exception('Unexpected error'));

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Unexpected error', $run->output['error']);
    }

    /** @test */
    public function it_trips_circuit_breaker_after_multiple_failures()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        // Create 5 recent failures
        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'status' => AgentExecutor::STATUS_FAILED,
            'created_at' => now()->subMinutes(30),
        ]);

        $failureResult = new ExecutionResult(
            success: false,
            output: ['error' => 'Another failure'],
            rawOutput: '',
            errorOutput: 'Another failure',
            exitCode: 1,
            durationSeconds: 1.0,
            tokensUsed: 0,
            costUsd: 0.0,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($failureResult);

        $this->executor->execute($agent);

        $agent->refresh();
        $this->assertNotNull($agent->circuit_broken_at);
    }

    /** @test */
    public function it_resets_circuit_breaker()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'circuit_broken_at' => now()->subHour(),
        ]);

        $this->executor->resetCircuitBreaker($agent);

        $agent->refresh();
        $this->assertNull($agent->circuit_broken_at);
    }

    /** @test */
    public function it_cancels_pending_run()
    {
        $agent = Agent::factory()->create(['status' => 'active']);
        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => AgentExecutor::STATUS_RUNNING,
        ]);

        $result = $this->executor->cancel($run);

        $this->assertEquals(AgentExecutor::STATUS_CANCELLED, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    /** @test */
    public function it_prevents_cancelling_completed_run()
    {
        $agent = Agent::factory()->create(['status' => 'active']);
        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => AgentExecutor::STATUS_COMPLETED,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Can only cancel pending runs');

        $this->executor->cancel($run);
    }

    /** @test */
    public function it_performs_dry_run_validation()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'config' => [
                'model' => 'sonnet',
                'max_budget_usd' => 5.0,
                'system_prompt' => 'You are a helpful assistant.',
                'tools' => ['web_search'],
            ],
        ]);

        $result = $this->executor->dryRun($agent);

        $this->assertTrue($result['valid']);
        $this->assertEquals('sonnet', $result['execution_preview']['model']);
        $this->assertEquals(5.0, $result['execution_preview']['max_budget_usd']);
        $this->assertTrue($result['execution_preview']['has_system_prompt']);
        $this->assertNotEmpty($result['execution_preview']['tools']);
    }

    /** @test */
    public function it_dry_run_shows_warnings_for_missing_config()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'config' => [],
        ]);

        $result = $this->executor->dryRun($agent);

        $this->assertTrue($result['valid']);
        $this->assertContains('Agent has no system prompt configured', $result['warnings']);
        $this->assertContains('Agent has no tools configured (will run with LLM reasoning only)', $result['warnings']);
    }

    /** @test */
    public function it_dry_run_detects_inactive_agent()
    {
        $agent = Agent::factory()->create(['status' => 'draft']);

        $result = $this->executor->dryRun($agent);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result['validation']);
    }

    /** @test */
    public function it_routes_output_after_successful_execution()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Routable output'],
            rawOutput: 'Routable output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 1.0,
            tokensUsed: 100,
            costUsd: 0.001,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $this->mockRouter->expects($this->once())
            ->method('route')
            ->willReturn(['wordpress_draft' => ['status' => 'success']]);

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_COMPLETED, $run->status);
    }

    /** @test */
    public function it_does_not_route_output_on_failure()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $failureResult = new ExecutionResult(
            success: false,
            output: ['error' => 'Failed'],
            rawOutput: '',
            errorOutput: 'Failed',
            exitCode: 1,
            durationSeconds: 1.0,
            tokensUsed: 0,
            costUsd: 0.0,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($failureResult);

        $this->mockRouter->expects($this->never())
            ->method('route');

        $run = $this->executor->execute($agent);

        $this->assertEquals(AgentExecutor::STATUS_FAILED, $run->status);
    }

    /** @test */
    public function it_executes_chained_agent_with_previous_output()
    {
        $agent1 = Agent::factory()->create(['status' => 'active']);
        $agent2 = Agent::factory()->create(['status' => 'active']);

        $previousRun = AgentRun::factory()->create([
            'agent_id' => $agent1->id,
            'status' => AgentExecutor::STATUS_COMPLETED,
            'output' => ['response' => 'Previous output'],
        ]);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Chained output'],
            rawOutput: 'Chained output',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 1.0,
            tokensUsed: 100,
            costUsd: 0.001,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $run = $this->executor->executeChained($agent2, $previousRun);

        $this->assertEquals(AgentRun::SOURCE_CHAINED, $run->invocation_source);
        $this->assertEquals('agent:'.$agent1->id, $run->invoked_by);
        $this->assertArrayHasKey('previous_run', $run->context);
    }

    /** @test */
    public function it_records_invocation_metadata()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $successResult = new ExecutionResult(
            success: true,
            output: ['response' => 'Test'],
            rawOutput: 'Test',
            errorOutput: '',
            exitCode: 0,
            durationSeconds: 1.0,
            tokensUsed: 100,
            costUsd: 0.001,
            workspace: null
        );

        $this->mockRunner->expects($this->once())
            ->method('execute')
            ->willReturn($successResult);

        $run = $this->executor->execute(
            agent: $agent,
            invocationSource: AgentRun::SOURCE_SCHEDULED,
            invokedBy: 'cron',
            triggerMetadata: ['schedule' => 'daily']
        );

        $this->assertEquals(AgentRun::SOURCE_SCHEDULED, $run->invocation_source);
        $this->assertEquals('cron', $run->invoked_by);
        $this->assertEquals(['schedule' => 'daily'], $run->trigger_metadata);
    }
}
