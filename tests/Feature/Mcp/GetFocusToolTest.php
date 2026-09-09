<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\GetFocusTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns focus briefing', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_PENDING_APPROVAL,
    ]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
        'description' => 'Approve Acme engineering run',
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(GetFocusTool::class, [
        'type' => 'briefing',
    ]);

    $response->assertOk();
    $response->assertSee('Approve Acme engineering run');
    $response->assertSee('critical');
});

test('returns filtered focus items', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->active()->create();
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_PENDING_APPROVAL,
    ]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
        'description' => 'High priority approval',
    ]);

    ApprovalRequest::factory()->create([
        'status' => 'pending',
        'risk_level' => 'low',
        'description' => 'Low priority approval',
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(GetFocusTool::class, [
        'type' => 'items',
        'priority_filter' => 'critical',
    ]);

    $response->assertOk();
    $response->assertSee('High priority approval');
    $response->assertDontSee('Low priority approval');
});
