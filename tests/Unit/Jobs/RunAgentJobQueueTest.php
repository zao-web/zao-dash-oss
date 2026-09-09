<?php

use App\Jobs\RunAgentJob;
use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\TriggerAgentTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('places mcp-triggered agent runs on the agents queue', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => 'admin']);
    $agent = Agent::factory()->create([
        'name' => 'WordPress Publisher',
        'slug' => 'word-press',
        'status' => 'active',
        'circuit_broken_at' => null,
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(TriggerAgentTool::class, [
        'slug' => 'word-press',
        'task' => 'Handshake only. Do not publish.',
    ]);

    $response->assertOk();

    Queue::assertPushed(RunAgentJob::class, function (RunAgentJob $job) use ($agent) {
        return $job->queue === 'agents'
            && $job->run->agent_id === $agent->id;
    });
});

it('constructs run agent jobs onto the agents queue', function () {
    $run = AgentRun::factory()->pending()->create();

    $job = new RunAgentJob($run);

    expect($job->queue)->toBe('agents');
});
