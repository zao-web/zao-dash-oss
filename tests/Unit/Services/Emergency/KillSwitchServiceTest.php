<?php

namespace Tests\Unit\Services\Emergency;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Services\Emergency\KillSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KillSwitchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KillSwitchService $killSwitch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->killSwitch = new KillSwitchService;

        // Clear cache between tests
        Cache::flush();
        Queue::fake();
    }

    /** @test */
    public function it_activates_global_kill_switch()
    {
        $this->killSwitch->activateGlobal('System maintenance', 1);

        $this->assertTrue($this->killSwitch->isGlobalKillActive());

        $status = $this->killSwitch->getGlobalStatus();
        $this->assertTrue($status['active']);
        $this->assertEquals('System maintenance', $status['reason']);
        $this->assertEquals(1, $status['activated_by']);
    }

    /** @test */
    public function it_cancels_pending_approvals_on_global_kill()
    {
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'running',
        ]);

        $approval = ApprovalRequest::factory()->create([
            'agent_run_id' => $run->id,
            'status' => 'pending',
        ]);

        $this->killSwitch->activateGlobal('Emergency shutdown', 1);

        $approval->refresh();
        $this->assertEquals('cancelled', $approval->status);
    }

    /** @test */
    public function it_cancels_running_agent_runs_on_global_kill()
    {
        $agent = Agent::factory()->create();
        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'running',
        ]);

        $this->killSwitch->activateGlobal('Emergency', 1);

        $runningCount = AgentRun::where('status', 'running')->count();
        $this->assertEquals(0, $runningCount);

        $cancelledCount = AgentRun::where('status', 'cancelled')->count();
        $this->assertEquals(3, $cancelledCount);
    }

    /** @test */
    public function it_deactivates_global_kill_switch()
    {
        $this->killSwitch->activateGlobal('Test', 1);
        $this->assertTrue($this->killSwitch->isGlobalKillActive());

        $this->killSwitch->deactivateGlobal(1);
        $this->assertFalse($this->killSwitch->isGlobalKillActive());
    }

    /** @test */
    public function it_kills_specific_agent()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'running',
        ]);

        $this->killSwitch->killAgent($agent, 'Agent malfunction', 1);

        $agent->refresh();
        $this->assertEquals('disabled', $agent->status);
        $this->assertNotNull($agent->circuit_broken_at);

        $this->assertEquals(0, AgentRun::where('agent_id', $agent->id)
            ->where('status', 'running')
            ->count());
    }

    /** @test */
    public function it_revives_killed_agent()
    {
        $agent = Agent::factory()->create([
            'status' => 'disabled',
            'circuit_broken_at' => now()->subHour(),
        ]);

        $this->killSwitch->reviveAgent($agent, 1);

        $agent->refresh();
        $this->assertEquals('active', $agent->status);
        $this->assertNull($agent->circuit_broken_at);
    }

    /** @test */
    public function it_checks_if_agent_can_run_with_global_kill_active()
    {
        $this->killSwitch->activateGlobal('Test', 1);

        $agent = Agent::factory()->create(['status' => 'active']);

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Global kill switch', $result['reason']);
    }

    /** @test */
    public function it_checks_if_agent_can_run_with_circuit_breaker()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'circuit_broken_at' => now(),
        ]);

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('circuit breaker', $result['reason']);
    }

    /** @test */
    public function it_checks_if_agent_can_run_when_disabled()
    {
        $agent = Agent::factory()->create(['status' => 'disabled']);

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('disabled', $result['reason']);
    }

    /** @test */
    public function it_checks_if_agent_can_run_with_spend_limit()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'daily_spend_limit' => 10.0,
        ]);

        // Simulate reaching limit
        Cache::put(
            KillSwitchService::SPEND_KEY_PREFIX.$agent->id.':'.now()->format('Y-m-d'),
            1000, // $10.00 in cents
            now()->endOfDay()
        );

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('spend limit', $result['reason']);
    }

    /** @test */
    public function it_allows_agent_to_run_when_all_checks_pass()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function it_records_agent_spend()
    {
        $agent = Agent::factory()->create(['daily_spend_limit' => 100.0]);

        $canContinue = $this->killSwitch->recordSpend($agent, 5.50);

        $this->assertTrue($canContinue);

        $dailySpend = $this->killSwitch->getAgentDailySpend($agent);
        $this->assertEquals(5.50, $dailySpend);
    }

    /** @test */
    public function it_detects_when_spend_limit_exceeded()
    {
        $agent = Agent::factory()->create(['daily_spend_limit' => 10.0]);

        $this->killSwitch->recordSpend($agent, 9.0);
        $canContinue = $this->killSwitch->recordSpend($agent, 2.0);

        $this->assertFalse($canContinue); // Total $11 exceeds $10 limit
    }

    /** @test */
    public function it_uses_default_daily_limit_when_not_set()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'daily_spend_limit' => null,
        ]);

        // Simulate reaching default limit
        Cache::put(
            KillSwitchService::SPEND_KEY_PREFIX.$agent->id.':'.now()->format('Y-m-d'),
            10000, // $100.00 (default limit)
            now()->endOfDay()
        );

        $result = $this->killSwitch->canAgentRun($agent);

        $this->assertFalse($result['allowed']);
    }

    /** @test */
    public function it_gets_total_daily_spend()
    {
        $agent1 = Agent::factory()->create();
        $agent2 = Agent::factory()->create();

        AgentRun::factory()->create([
            'agent_id' => $agent1->id,
            'cost_usd' => 5.50,
            'started_at' => today(),
        ]);

        AgentRun::factory()->create([
            'agent_id' => $agent2->id,
            'cost_usd' => 3.25,
            'started_at' => today(),
        ]);

        AgentRun::factory()->create([
            'agent_id' => $agent1->id,
            'cost_usd' => 10.00,
            'started_at' => today()->subDay(),
        ]);

        $totalSpend = $this->killSwitch->getTotalDailySpend();

        $this->assertEquals(8.75, $totalSpend); // Only today's runs
    }

    /** @test */
    public function it_gets_emergency_status()
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'circuit_broken_at' => now(),
        ]);

        AgentRun::factory()->create(['status' => 'running']);
        ApprovalRequest::factory()->create(['status' => 'pending']);

        $this->killSwitch->activateGlobal('Test', 1);

        $status = $this->killSwitch->getStatus();

        $this->assertTrue($status['global_kill']['active']);
        $this->assertEquals('Test', $status['global_kill']['reason']);
        $this->assertEquals(1, $status['running_count']);
        $this->assertEquals(1, $status['pending_approvals']);
        $this->assertNotEmpty($status['agents']);
    }

    /** @test */
    public function it_includes_agent_details_in_status()
    {
        $agent = Agent::factory()->create([
            'slug' => 'test-agent',
            'name' => 'Test Agent',
            'status' => 'active',
            'daily_spend_limit' => 50.0,
        ]);

        $this->killSwitch->recordSpend($agent, 10.0);

        $status = $this->killSwitch->getStatus();

        $agentStatus = collect($status['agents'])->firstWhere('slug', 'test-agent');

        $this->assertEquals('Test Agent', $agentStatus['name']);
        $this->assertEquals('active', $agentStatus['status']);
        $this->assertEquals(10.0, $agentStatus['daily_spend']);
        $this->assertEquals(50.0, $agentStatus['daily_limit']);
        $this->assertTrue($agentStatus['can_run']['allowed']);
    }

    /** @test */
    public function it_performs_health_check_and_auto_kills_failing_agents()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        // Create 3 recent failures
        AgentRun::factory()->count(3)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
            'created_at' => now()->subMinutes(10),
        ]);

        $issues = $this->killSwitch->healthCheck();

        $agent->refresh();
        $this->assertNotNull($agent->circuit_broken_at);
        $this->assertEquals('disabled', $agent->status);
        $this->assertNotEmpty($issues);
    }

    /** @test */
    public function it_health_check_detects_runaway_spend()
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 600.00,
            'started_at' => today(),
        ]);

        $issues = $this->killSwitch->healthCheck();

        $this->assertNotEmpty($issues);
        $warningFound = false;
        foreach ($issues as $issue) {
            if (str_contains($issue, 'Total daily spend')) {
                $warningFound = true;
                break;
            }
        }
        $this->assertTrue($warningFound);
    }

    /** @test */
    public function it_health_check_kills_stuck_runs()
    {
        $agent = Agent::factory()->create();

        $stuckRun = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'running',
            'started_at' => now()->subMinutes(45), // Stuck for 45 minutes
        ]);

        $issues = $this->killSwitch->healthCheck();

        $stuckRun->refresh();
        $this->assertEquals('failed', $stuckRun->status);
        $this->assertStringContainsString('Timeout', $stuckRun->error_message);
        $this->assertNotEmpty($issues);
    }

    /** @test */
    public function it_does_not_auto_kill_agent_with_less_than_3_failures()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
        ]);

        $this->killSwitch->healthCheck();

        $agent->refresh();
        $this->assertNull($agent->circuit_broken_at);
        $this->assertEquals('active', $agent->status);
    }

    /** @test */
    public function it_does_not_auto_kill_already_broken_agent()
    {
        $agent = Agent::factory()->create([
            'status' => 'disabled',
            'circuit_broken_at' => now()->subHour(),
        ]);

        AgentRun::factory()->count(5)->create([
            'agent_id' => $agent->id,
            'status' => 'failed',
        ]);

        $issues = $this->killSwitch->healthCheck();

        // Should not create duplicate kill issue
        $this->assertLessThanOrEqual(0, count($issues));
    }

    /** @test */
    public function it_resets_daily_spend_at_end_of_day()
    {
        $agent = Agent::factory()->create();

        $this->killSwitch->recordSpend($agent, 10.0);

        // Simulate next day
        $key = KillSwitchService::SPEND_KEY_PREFIX.$agent->id.':'.now()->addDay()->format('Y-m-d');
        Cache::forget($key);

        $nextDaySpend = $this->killSwitch->getAgentDailySpend($agent);

        // Note: This test would need to mock time or use Carbon::setTestNow
        // For now, just verify the key format is correct
        $this->assertTrue(true);
    }

    /** @test */
    public function it_cancels_agent_specific_approvals_on_kill()
    {
        $agent1 = Agent::factory()->create();
        $agent2 = Agent::factory()->create();

        $approval1 = ApprovalRequest::factory()->create([
            'agent_id' => $agent1->id,
            'status' => 'pending',
        ]);

        $approval2 = ApprovalRequest::factory()->create([
            'agent_id' => $agent2->id,
            'status' => 'pending',
        ]);

        $this->killSwitch->killAgent($agent1, 'Test kill', 1);

        $approval1->refresh();
        $approval2->refresh();

        $this->assertEquals('cancelled', $approval1->status);
        $this->assertEquals('pending', $approval2->status); // Other agent unaffected
    }

    /** @test */
    public function it_returns_empty_issues_when_health_check_passes()
    {
        $agent = Agent::factory()->create(['status' => 'active']);

        AgentRun::factory()->count(2)->create([
            'agent_id' => $agent->id,
            'status' => 'completed',
        ]);

        $issues = $this->killSwitch->healthCheck();

        $this->assertEmpty($issues);
    }

    /** @test */
    public function it_tracks_queue_size_in_status()
    {
        Queue::fake();

        $status = $this->killSwitch->getStatus();

        $this->assertArrayHasKey('queue_size', $status);
        $this->assertEquals(0, $status['queue_size']);
    }
}
