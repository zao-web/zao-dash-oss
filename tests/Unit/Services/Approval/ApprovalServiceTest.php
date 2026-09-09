<?php

namespace Tests\Unit\Services\Approval;

use App\Events\NotificationCreated;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalService;
        Event::fake();
        Log::spy();
    }

    /** @test */
    public function it_creates_approval_request()
    {
        $run = AgentRun::factory()->create();

        $approval = $this->service->createRequest(
            $run,
            'deploy',
            'Deploy to production',
            ['environment' => 'production'],
            'Test deployment'
        );

        $this->assertDatabaseHas('approval_requests', [
            'agent_run_id' => $run->id,
            'category' => 'deploy',
            'title' => 'Deploy to production',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_auto_approves_when_conditions_met()
    {
        config(['approval_policies.categories.test' => [
            'risk_level' => 'low',
            'auto_approve_conditions' => ['amount_under' => 1000],
        ]]);

        $run = AgentRun::factory()->create();

        $approval = $this->service->createRequest(
            $run,
            'test',
            'Small expense',
            ['amount' => 500]
        );

        $this->assertEquals('approved', $approval->status);
        $this->assertEquals('Auto-approved by policy', $approval->review_notes);
    }

    /** @test */
    public function it_does_not_auto_approve_critical_actions()
    {
        config(['approval_policies.categories.critical_action' => [
            'risk_level' => 'critical',
            'auto_approve_conditions' => ['amount_under' => 1000],
        ]]);

        $run = AgentRun::factory()->create();

        $approval = $this->service->createRequest(
            $run,
            'critical_action',
            'Critical action',
            ['amount' => 100]
        );

        $this->assertEquals('pending', $approval->status);
    }

    /** @test */
    public function it_approves_pending_request()
    {
        $approval = ApprovalRequest::factory()->pending()->create();
        $approver = User::factory()->create();

        $result = $this->service->approve($approval, $approver, 'Looks good');

        $this->assertEquals('approved', $result->status);
        $this->assertEquals($approver->id, $result->reviewed_by);
        $this->assertEquals('Looks good', $result->review_notes);
        $this->assertNotNull($result->reviewed_at);

        Event::assertDispatched(NotificationCreated::class);
    }

    /** @test */
    public function it_throws_exception_when_approving_non_pending_request()
    {
        $approval = ApprovalRequest::factory()->approved()->create();
        $approver = User::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot approve request with status: approved');

        $this->service->approve($approval, $approver);
    }

    /** @test */
    public function it_requires_2fa_for_critical_approvals()
    {
        config(['approval_policies.categories.critical' => [
            'risk_level' => 'critical',
            'requires_2fa' => true,
        ]]);

        $approval = ApprovalRequest::factory()->pending()->create([
            'category' => 'critical',
            'risk_level' => 'critical',
        ]);

        $approver = User::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('2FA verification required');

        $this->service->approve($approval, $approver);
    }

    /** @test */
    public function it_rejects_pending_request()
    {
        $approval = ApprovalRequest::factory()->pending()->create();
        $rejector = User::factory()->create();

        $result = $this->service->reject($approval, $rejector, 'Not safe');

        $this->assertEquals('rejected', $result->status);
        $this->assertEquals($rejector->id, $result->reviewed_by);
        $this->assertEquals('Not safe', $result->review_notes);

        Event::assertDispatched(NotificationCreated::class);
    }

    /** @test */
    public function it_cancels_pending_request()
    {
        $approval = ApprovalRequest::factory()->pending()->create();

        $result = $this->service->cancel($approval, 'No longer needed');

        $this->assertEquals('cancelled', $result->status);
        $this->assertEquals('No longer needed', $result->review_notes);
    }

    /** @test */
    public function it_gets_pending_approvals()
    {
        ApprovalRequest::factory()->pending()->count(5)->create();
        ApprovalRequest::factory()->approved()->count(3)->create();
        ApprovalRequest::factory()->rejected()->count(2)->create();

        $pending = $this->service->getPending();

        $this->assertCount(5, $pending);
    }

    /** @test */
    public function it_filters_pending_approvals_by_category()
    {
        ApprovalRequest::factory()->pending()->create(['category' => 'deploy']);
        ApprovalRequest::factory()->pending()->create(['category' => 'expense']);
        ApprovalRequest::factory()->pending()->create(['category' => 'deploy']);

        $pending = $this->service->getPending('deploy');

        $this->assertCount(2, $pending);
    }

    /** @test */
    public function it_orders_pending_by_risk_level()
    {
        $low = ApprovalRequest::factory()->pending()->create(['risk_level' => 'low']);
        $critical = ApprovalRequest::factory()->pending()->create(['risk_level' => 'critical']);
        $medium = ApprovalRequest::factory()->pending()->create(['risk_level' => 'medium']);
        $high = ApprovalRequest::factory()->pending()->create(['risk_level' => 'high']);

        $pending = $this->service->getPending();

        $this->assertEquals('critical', $pending[0]->risk_level);
        $this->assertEquals('high', $pending[1]->risk_level);
        $this->assertEquals('medium', $pending[2]->risk_level);
        $this->assertEquals('low', $pending[3]->risk_level);
    }

    /** @test */
    public function it_gets_stale_approvals()
    {
        config(['approval_policies.escalation.escalate_after_hours' => 12]);

        ApprovalRequest::factory()->pending()->create([
            'created_at' => now()->subHours(6),
        ]);

        $stale = ApprovalRequest::factory()->pending()->create([
            'created_at' => now()->subHours(15),
        ]);

        $result = $this->service->getStaleApprovals();

        $this->assertCount(1, $result);
        $this->assertEquals($stale->id, $result[0]->id);
    }

    /** @test */
    public function it_escalates_stale_approvals()
    {
        config([
            'approval_policies.escalation.escalate_after_hours' => 12,
            'approval_policies.escalation.escalate_to' => ['owner'],
        ]);

        User::factory()->create(['role' => 'owner']);

        ApprovalRequest::factory()->pending()->create([
            'created_at' => now()->subHours(15),
        ]);

        $count = $this->service->escalateStale();

        $this->assertEquals(1, $count);
        Event::assertDispatched(NotificationCreated::class);
    }

    /** @test */
    public function it_expires_old_approvals()
    {
        ApprovalRequest::factory()->pending()->create([
            'expires_at' => now()->addHours(1),
        ]);

        $expired = ApprovalRequest::factory()->pending()->create([
            'expires_at' => now()->subHours(1),
        ]);

        $count = $this->service->expireOld();

        $this->assertEquals(1, $count);
        $this->assertEquals('expired', $expired->fresh()->status);
    }

    /** @test */
    public function it_cancels_all_pending_approvals()
    {
        ApprovalRequest::factory()->pending()->count(5)->create();
        ApprovalRequest::factory()->approved()->count(2)->create();

        $count = $this->service->cancelAllPending('Emergency stop');

        $this->assertEquals(5, $count);
        $this->assertEquals(0, ApprovalRequest::where('status', 'pending')->count());
    }

    /** @test */
    public function it_evaluates_auto_approve_conditions()
    {
        $run = AgentRun::factory()->create();

        // Test amount_under
        $this->assertTrue(
            $this->service->canAutoApprove('test', ['amount' => 50])
        );

        config(['approval_policies.categories.test' => [
            'auto_approve_conditions' => ['amount_under' => 100],
        ]]);

        $this->assertTrue(
            $this->service->canAutoApprove('test', ['amount' => 50])
        );

        $this->assertFalse(
            $this->service->canAutoApprove('test', ['amount' => 150])
        );
    }

    /** @test */
    public function it_evaluates_existing_client_condition()
    {
        config(['approval_policies.categories.test' => [
            'auto_approve_conditions' => ['existing_client' => true],
        ]]);

        $this->assertTrue(
            $this->service->canAutoApprove('test', ['client_id' => 123])
        );

        $this->assertFalse(
            $this->service->canAutoApprove('test', [])
        );
    }

    /** @test */
    public function it_evaluates_tests_pass_condition()
    {
        config(['approval_policies.categories.deploy' => [
            'auto_approve_conditions' => ['tests_pass' => true],
        ]]);

        $this->assertTrue(
            $this->service->canAutoApprove('deploy', ['tests_passed' => true])
        );

        $this->assertFalse(
            $this->service->canAutoApprove('deploy', ['tests_passed' => false])
        );
    }

    /** @test */
    public function it_gets_approval_stats()
    {
        ApprovalRequest::factory()->pending()->count(3)->create();
        ApprovalRequest::factory()->approved()->create(['reviewed_at' => today()]);
        ApprovalRequest::factory()->rejected()->create(['reviewed_at' => today()]);

        $stats = $this->service->getStats();

        $this->assertEquals(3, $stats['pending']);
        $this->assertEquals(1, $stats['approved_today']);
        $this->assertEquals(1, $stats['rejected_today']);
        $this->assertIsArray($stats['by_risk_level']);
    }

    /** @test */
    public function it_notifies_appropriate_roles()
    {
        config(['approval_policies.categories.test' => [
            'notify_roles' => ['owner', 'admin'],
        ]]);

        User::factory()->create(['role' => 'owner']);
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'user']);

        $run = AgentRun::factory()->create();

        $this->service->createRequest(
            $run,
            'test',
            'Test approval',
            []
        );

        Event::assertDispatched(NotificationCreated::class, 2);
    }
}
