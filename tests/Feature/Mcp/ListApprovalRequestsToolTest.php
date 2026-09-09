<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\ListApprovalRequestsTool;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('lists pending approval requests', function () {
    $user = User::factory()->create();

    ApprovalRequest::factory()->create([
        'description' => 'Deploy Acme production site',
        'risk_level' => 'high',
        'status' => 'pending',
    ]);

    ApprovalRequest::factory()->approved()->create([
        'description' => 'Already approved request',
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(ListApprovalRequestsTool::class, [
        'status' => 'pending',
    ]);

    $response->assertOk();
    $response->assertSee('Deploy Acme production site');
    $response->assertDontSee('Already approved request');
});
