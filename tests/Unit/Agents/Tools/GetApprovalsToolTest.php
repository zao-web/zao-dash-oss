<?php

use App\Agents\Tools\GetApprovalsTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new GetApprovalsTool;
    expect($tool->name())->toBe('Get Approvals');
});

test('getDescription returns correct description', function () {
    $tool = new GetApprovalsTool;
    expect($tool->description())->toContain('pending approval');
});

test('getParameters includes status and limit', function () {
    $tool = new GetApprovalsTool;
    $schema = $tool->inputSchema();

    expect($schema['properties'])->toHaveKeys(['status', 'limit'])
        ->and($schema['properties']['status']['enum'])->toContain('pending', 'approved', 'rejected');
});

test('execute returns pending approvals by default', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->count(3)->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
    ]);
    ApprovalRequest::factory()->count(2)->create([
        'agent_run_id' => $run->id,
        'status' => 'approved',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['count', 'approvals'])
        ->and($result['count'])->toBe(3);
});

test('execute filters by status', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'approved',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute(['status' => 'approved']);

    expect($result['count'])->toBe(1)
        ->and($result['approvals'][0]['status'])->toBe('approved');
});

test('execute respects limit parameter', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->count(20)->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute(['limit' => 5]);

    expect($result['count'])->toBe(5);
});

test('execute defaults to limit of 10', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->count(20)->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute([]);

    expect($result['count'])->toBe(10);
});

test('execute includes agent name in response', function () {
    $agent = Agent::factory()->create(['name' => 'Test Agent']);
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute([]);

    expect($result['approvals'][0]['agent'])->toBe('Test Agent');
});

test('execute includes all approval fields', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'action_type' => 'create_task',
        'description' => 'Create new task',
        'status' => 'pending',
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute([]);

    expect($result['approvals'][0])->toHaveKeys([
        'id', 'action_type', 'description', 'status', 'agent', 'created_at',
    ])->and($result['approvals'][0]['action_type'])->toBe('create_task')
        ->and($result['approvals'][0]['description'])->toBe('Create new task');
});

test('execute orders by newest first', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

    $old = ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'created_at' => now()->subHours(2),
    ]);

    $new = ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'created_at' => now(),
    ]);

    $tool = new GetApprovalsTool;
    $result = $tool->execute([]);

    expect($result['approvals'][0]['id'])->toBe($new->id);
});

test('validate rejects invalid status', function () {
    $tool = new GetApprovalsTool;
    $tool->validate(['status' => 'invalid']);
})->throws(InvalidArgumentException::class);

test('validate rejects limit over max', function () {
    $tool = new GetApprovalsTool;
    $tool->validate(['limit' => 100]);
})->throws(InvalidArgumentException::class);

test('validate accepts valid parameters', function () {
    $tool = new GetApprovalsTool;
    $validated = $tool->validate([
        'status' => 'approved',
        'limit' => 20,
    ]);

    expect($validated)->toHaveKeys(['status', 'limit']);
});
