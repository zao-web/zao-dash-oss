<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\GetChannelOperationsContextTool;
use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use App\Models\PmConnection;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Models\Task;
use App\Models\User;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns operating context for a slack channel', function () {
    $user = User::factory()->create();
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T_ops',
        'workspace_name' => 'Ops Workspace',
    ]);
    $client = Client::factory()->create([
        'name' => 'Acme',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C_ops',
        'channel_name' => 'acme-ops',
        'client_id' => $client->id,
        'classification' => 'client',
        'monitoring_enabled' => true,
    ]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Refresh',
        'status' => 'active',
        'slack_channel_id' => $channel->id,
    ]);

    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Ship launch page',
        'status' => 'in_progress',
    ]);

    $installation = GitHubInstallation::factory()->create([
        'account_login' => 'acme-org',
        'account_type' => 'Organization',
    ]);

    GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme-org/website-refresh',
        'name' => 'website-refresh',
        'monitoring_enabled' => true,
    ]);

    PmConnection::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'platform' => 'clickup',
        'workspace_name' => 'Acme ClickUp',
        'workspace_id' => 'cu_123',
        'access_token' => 'test-token',
        'is_active' => true,
    ]);

    $wordPressSite = WordPressSite::factory()->create([
        'client_id' => $client->id,
        'name' => 'Acme Marketing Site',
        'url' => 'https://acme.test',
        'mcp_enabled' => true,
    ]);

    $server = SpinupWpServer::factory()->create([
        'name' => 'Acme Server',
    ]);

    SpinupWpSite::factory()->create([
        'spinup_server_id' => $server->id,
        'wordpress_site_id' => $wordPressSite->id,
        'domain' => 'acme.test',
        'status' => 'deployed',
    ]);

    $response = ZaoDashServer::actingAs($user)->tool(GetChannelOperationsContextTool::class, [
        'workspace_id' => 'T_ops',
        'channel_id' => 'C_ops',
    ]);

    $response->assertOk();
    $response->assertSee('Acme');
    $response->assertSee('Website Refresh');
    $response->assertSee('website-refresh');
    $response->assertSee('Acme ClickUp');
    $response->assertSee('Acme Marketing Site');
    $response->assertSee('acme.test');
});
