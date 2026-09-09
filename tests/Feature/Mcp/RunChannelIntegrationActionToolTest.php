<?php

use App\Jobs\SyncClickUpJob;
use App\Jobs\SyncGitHubJob;
use App\Jobs\SyncHarvestJob;
use App\Jobs\SyncWordPressJob;
use App\Mcp\Servers\ZaoCommsServer;
use App\Mcp\Tools\RunChannelIntegrationActionTool;
use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use App\Models\HarvestCredential;
use App\Models\PmConnection;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('queues integration syncs for a linked slack channel', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T_sync',
    ]);
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C_sync',
        'client_id' => $client->id,
    ]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'slack_channel_id' => $channel->id,
    ]);
    $installation = GitHubInstallation::factory()->create();

    GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);

    PmConnection::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'platform' => 'clickup',
        'workspace_name' => 'Acme ClickUp',
        'workspace_id' => 'cu_123',
        'access_token' => 'token',
        'is_active' => true,
    ]);

    HarvestCredential::create([
        'user_id' => $user->id,
        'access_token' => 'harvest-token',
        'refresh_token' => 'refresh-token',
        'account_id' => 'acct_123',
        'account_name' => 'Acme Harvest',
        'is_active' => true,
        'expires_at' => now()->addDay(),
    ]);

    WordPressSite::factory()->create([
        'client_id' => $client->id,
        'name' => 'Acme Site',
    ]);

    $response = ZaoCommsServer::actingAs($user)->tool(RunChannelIntegrationActionTool::class, [
        'workspace_id' => 'T_sync',
        'channel_id' => 'C_sync',
        'action' => 'sync-all',
    ]);

    $response->assertOk();
    $response->assertSee('Queued all available integration sync actions');

    Queue::assertPushed(SyncGitHubJob::class);
    Queue::assertPushed(SyncClickUpJob::class);
    Queue::assertPushed(SyncHarvestJob::class);
    Queue::assertPushed(SyncWordPressJob::class);
});
