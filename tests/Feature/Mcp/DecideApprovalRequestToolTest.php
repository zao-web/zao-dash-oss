<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\DecideApprovalRequestTool;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('approves a pending approval request', function () {
    $user = User::factory()->create([
        'role' => 'admin',
    ]);

    $approval = ApprovalRequest::factory()->create([
        'status' => 'pending',
        'description' => 'Approve deployment window',
        'action_type' => 'deploy_code',
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(DecideApprovalRequestTool::class, [
        'approval_id' => $approval->id,
        'decision' => 'approve',
        'note' => 'Looks safe.',
    ]);

    $response->assertOk();
    $response->assertSee('approved');

    expect($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->decision_note)->toBe('Looks safe.')
        ->and($approval->fresh()->decided_by)->toBe($user->id);
});
