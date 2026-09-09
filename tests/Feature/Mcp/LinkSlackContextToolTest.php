<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\LinkSlackContextTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('links a slack channel to client and project', function () {
    $user = User::factory()->create();
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T_link',
    ]);
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C_link',
        'client_id' => null,
        'classification' => 'general',
        'monitoring_enabled' => false,
    ]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => null,
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(LinkSlackContextTool::class, [
        'workspace_id' => 'T_link',
        'channel_id' => 'C_link',
        'client_id' => $client->id,
        'project_id' => $project->id,
        'monitoring_enabled' => true,
    ]);

    $response->assertOk();
    $response->assertSee($client->name);
    $response->assertSee($project->name);

    expect($channel->fresh()->client_id)->toBe($client->id)
        ->and($channel->fresh()->classification)->toBe('client')
        ->and($channel->fresh()->monitoring_enabled)->toBeTrue()
        ->and($client->fresh()->slack_channel_id)->toBe($channel->id)
        ->and($project->fresh()->slack_channel_id)->toBe($channel->id);
});
