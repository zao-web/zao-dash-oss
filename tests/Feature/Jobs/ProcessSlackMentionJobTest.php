<?php

use App\Enums\SlackActionType;
use App\Jobs\ProcessSlackMentionJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\User;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use App\Services\Slack\SlackControlPlaneService;
use App\Services\Slack\SlackEngineeringApprovalService;
use App\Services\Slack\SlackMcpToolBridge;
use App\Services\Slack\SlackMentionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiService = Mockery::mock(SlackApiService::class);
    $this->apiService->shouldReceive('postMessage')->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $this->apiService);
});

test('job processes create task intent', function () {
    User::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create a task to update the documentation',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('tasks', [
        'title' => 'update the documentation',
        'project_id' => $project->id,
    ]);

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job processes log note intent', function () {
    User::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'log note: client approved the wireframes',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('client_notes', [
        'client_id' => $client->id,
        'content' => 'client approved the wireframes',
    ]);
});

test('job processes watchlist tracking intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_name' => 'acme-client',
        'monitoring_enabled' => true,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'context_data' => [
            'slack_user_id' => 'U12345',
            'invoked_via' => 'direct_message',
        ],
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'track acme-client',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('slack_user_watchlist_items', [
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
        'is_active' => true,
    ]);

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job requests confirmation for trigger agent intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $agent = Agent::factory()->create([
        'slug' => 'dev-agent',
        'name' => 'Dev Agent',
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'pending_actions' => [],
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'run the dev-agent to fix the auth bug',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('awaiting_response')
        ->and($context->pending_actions)->toHaveCount(1)
        ->and($context->pending_actions[0]['type'])->toBe('trigger_agent')
        ->and($context->pending_actions[0]['data']['agent_slug'])->toBe('dev-agent');
});

test('job requests confirmation for engineering issue intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/repo',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'pending_actions' => [],
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'work issue #42 on staging branch',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('awaiting_response')
        ->and($context->pending_actions)->toHaveCount(1)
        ->and($context->pending_actions[0]['type'])->toBe('trigger_engineering_agent')
        ->and($context->pending_actions[0]['data']['issue_number'])->toBe(42)
        ->and($context->pending_actions[0]['data']['delivery_target'])->toBe('staging');
});

test('job requests confirmation for sow import intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'pending_actions' => [],
    ]);

    $orchestrator = Mockery::mock(SlackMentionOrchestrator::class);
    $orchestrator->shouldReceive('detectIntent')
        ->once()
        ->andReturn([
            'type' => SlackActionType::ImportSow->value,
            'google_doc_urls' => ['https://docs.google.com/document/d/abc123/edit'],
            'link_to_channel' => true,
            'create_invoices' => true,
        ]);
    $orchestrator->shouldReceive('requiresConfirmation')
        ->once()
        ->andReturnTrue();
    $orchestrator->shouldReceive('previewImportSow')
        ->once()
        ->andReturn([
            'success' => true,
            'parsed' => [
                'client_name' => 'Acme Corp',
                'project_name' => 'Launch Project',
                'milestone_count' => 2,
                'invoice_count' => 1,
                'client' => ['name' => 'Acme Corp'],
                'project' => ['name' => 'Launch Project', 'type' => 'project'],
                'milestones' => [],
                'invoices' => [],
                'billing' => [],
                'link_to_channel' => true,
            ],
        ]);
    $orchestrator->shouldReceive('sendBlockResponse')
        ->once();

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'Please spin up this approved SOW from https://docs.google.com/document/d/abc123/edit and link this channel',
        'U12345'
    );

    $job->handle(
        $orchestrator,
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('awaiting_response')
        ->and($context->pending_actions)->toHaveCount(1)
        ->and($context->pending_actions[0]['type'])->toBe('import_sow')
        ->and($context->pending_actions[0]['data']['google_doc_urls'])->toBe(['https://docs.google.com/document/d/abc123/edit']);
});

test('job processes search intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    Project::factory()->create(['name' => 'Authentication System']);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'find projects about authentication',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job processes status intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'monitoring_enabled' => true,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "what's the status?",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job processes focus intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);
    $agent = Agent::factory()->active()->create([
        'name' => 'Dev Agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_PENDING_APPROVAL,
    ]);

    \App\Models\ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
        'description' => 'Approve today plan',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'help me prioritize today',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job processes thread summary intent', function () {
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->withArgs(function ($workspace, $channelId, $text, $options) {
            return $channelId !== ''
                && $text === ''
                && str_contains(json_encode($options['blocks'] ?? []), 'Thread Status');
        })
        ->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $api);

    User::factory()->create(['role' => 'admin']);
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = \App\Models\AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'completed',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/acme/platform/pull/17',
            'pr_number' => 17,
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'what are we working on in this thread?',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes request review deploy intent and responds with refreshed thread summary', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $deployService = Mockery::mock(SlackEngineeringApprovalService::class);
    app()->instance(SlackEngineeringApprovalService::class, $deployService);

    $bridge = Mockery::mock(SlackMcpToolBridge::class);
    app()->instance(SlackMcpToolBridge::class, $bridge);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'completed',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/acme/platform/pull/17',
            'pr_number' => 17,
            'branch' => 'feature/fix-42',
            'deployment_status' => 'queued',
            'workflow_status' => 'requested',
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
    ]);

    $deployService->shouldReceive('requestReviewDeploy')
        ->once()
        ->with(Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id))
        ->andReturn([
            'success' => true,
            'message' => 'Requested review deploy for PR #17 on `feature/fix-42`.',
        ]);

    $bridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'promote this PR to staging',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks);

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('Requested review deploy for PR #17')
        ->and($blocksJson)->toContain('Thread Status');
});

test('job processes cancel run intent and responds with refreshed thread summary', function () {
    Queue::fake();
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $bridge = Mockery::mock(SlackMcpToolBridge::class);
    app()->instance(SlackMcpToolBridge::class, $bridge);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->active()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => AgentRun::STATUS_RUNNING,
        'context' => [
            'slack' => [
                'workspace_id' => 'T12345',
                'channel_id' => 'C12345',
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'repo' => 'acme/platform',
            ],
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
    ]);

    $bridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'cancel this run',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $run->refresh();
    $blocksJson = json_encode($capturedBlocks);

    expect($run->status)->toBe(AgentRun::STATUS_CANCELLED)
        ->and($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('Cancelled Run #'.$run->id)
        ->and($blocksJson)->toContain('Thread Status');
});

test('job processes prepare staging secret intent and responds with staging workflow blocks', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
        'slack_channel_id' => null,
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $repo = GitHubRepo::factory()->create([
        'installation_id' => GitHubInstallation::factory(),
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
    ]);
    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'staging_url' => 'https://staging.acme.test',
        'onboarding_completed' => false,
    ]);
    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'add VERCEL_TOKEN for staging',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks, JSON_UNESCAPED_SLASHES);

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('securely add `VERCEL_TOKEN`')
        ->and($blocksJson)->toContain('Staging Workflow')
        ->and($blocksJson)->toContain('staging_open_secret_modal')
        ->and($blocksJson)->toContain('VERCEL_TOKEN');
});

test('job processes publish staging intent and responds with staging workflow blocks', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'slack_channel_id' => $channel->id,
    ]);
    $repo = GitHubRepo::factory()->create([
        'installation_id' => GitHubInstallation::factory(),
        'client_id' => $client->id,
        'project_id' => $project->id,
        'full_name' => 'acme/platform',
        'default_branch' => 'main',
    ]);
    \App\Models\DeploymentConfig::query()->create([
        'client_id' => $client->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => false,
    ]);
    $secret = \App\Models\VaultSecret::factory()->create([
        'project_id' => $project->id,
        'client_id' => $project->client_id,
        'key' => 'VERCEL_TOKEN',
        'name' => 'VERCEL_TOKEN',
    ]);
    \App\Models\VaultSecretValue::factory()->create([
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('ready-value'),
        'is_active' => true,
    ]);
    \App\Models\GitHubWorkflowSecretRequirement::factory()->create([
        'github_repo_id' => $repo->id,
        'workflow_path' => '.github/workflows/deploy.yml',
        'secret_name' => 'VERCEL_TOKEN',
        'vault_secret_id' => $secret->id,
        'match_status' => \App\Models\GitHubWorkflowSecretRequirement::MATCH_STATUS_MATCHED,
        'is_required' => true,
        'source' => \App\Models\GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
    ]);
    \App\Models\VaultSecretGitHubTarget::factory()->create([
        'github_repo_id' => $repo->id,
        'vault_secret_id' => $secret->id,
        'environment' => 'staging',
        'github_secret_name' => 'VERCEL_TOKEN',
        'github_environment_name' => 'staging',
        'drift_status' => \App\Models\VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'publish this project to staging',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks);

    $approval = \App\Models\ApprovalRequest::query()->latest('id')->first();

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($approval)->not->toBeNull()
        ->and($approval?->action_type)->toBe('slack_staging_publish')
        ->and($blocksJson)->toContain('Queued approval #')
        ->and($blocksJson)->toContain('Pending Approvals')
        ->and($blocksJson)->toContain('Staging Workflow');
});

test('job processes list agent runs intent and responds with run list blocks', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
    AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'running',
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'task' => 'Fix login bug',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'delivery_target' => 'staging',
                'repo' => 'owner/repo',
            ],
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'show active runs',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks, JSON_UNESCAPED_SLASHES);

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('Active Runs')
        ->and($blocksJson)->toContain('Platform Refresh')
        ->and($blocksJson)->toContain('Fix login bug');
});

test('job processes show agent run intent and responds with run detail blocks', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $project->update(['slack_channel_id' => $channel->id]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => 'completed',
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'delivery_target' => 'pr',
                'repo' => 'owner/repo',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/owner/repo/pull/17',
            'pr_number' => 17,
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "show run #{$run->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks, JSON_UNESCAPED_SLASHES);

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('Agent Run')
        ->and($blocksJson)->toContain('Run #'.$run->id)
        ->and($blocksJson)->toContain('Open PR');
});

test('job processes open review build intent for a specific pr and responds with direct artifact link', function () {
    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $bridge = Mockery::mock(SlackMcpToolBridge::class);
    app()->instance(SlackMcpToolBridge::class, $bridge);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $currentRun = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'status' => 'completed',
        'context' => [
            'slack' => ['channel_id' => 'C12345'],
            'engineering' => [
                'issue_number' => 99,
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/acme/platform/pull/18',
            'pr_number' => 18,
            'branch' => 'feature/fix-18',
        ],
    ]);
    $targetRun = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'status' => 'completed',
        'context' => [
            'slack' => ['channel_id' => 'C12345'],
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/acme/platform/pull/17',
            'pr_number' => 17,
            'branch' => 'feature/fix-17',
            'staging_url' => 'https://staging.acme.test',
            'deployment_status' => 'deployed',
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $currentRun->id,
        'context_data' => [
            'project_id' => $project->id,
            'project_repo' => 'acme/platform',
        ],
    ]);

    $bridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'open the review build for PR #17',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $blocksJson = json_encode($capturedBlocks);

    expect($context->fresh()->current_state)->toBe('idle')
        ->and($blocksJson)->toContain('PR #17 review build')
        ->and($blocksJson)->toContain('staging.acme.test')
        ->and($blocksJson)->toContain('Thread Status');
});

test('job processes retry agent run intent and responds with refreshed thread summary', function () {
    Queue::fake();

    $capturedBlocks = [];
    $api = Mockery::mock(SlackApiService::class);
    $api->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspace, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });
    app()->instance(SlackApiService::class, $api);

    $bridge = Mockery::mock(SlackMcpToolBridge::class);
    app()->instance(SlackMcpToolBridge::class, $bridge);

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_FAILED,
        'task' => 'Investigate checkout issue',
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => 'T12345',
                'channel_id' => 'C12345',
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'context_data' => [
            'slack_user_id' => 'U12345',
        ],
    ]);

    $bridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'channel' => ['name' => 'eng'],
        ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'reopen the failed run',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $newRun = AgentRun::query()->whereKeyNot($run->id)->latest('id')->first();
    $blocksJson = json_encode($capturedBlocks);

    expect($newRun)->not->toBeNull()
        ->and($context->fresh()->agent_run_id)->toBe($newRun->id)
        ->and($blocksJson)->toContain('Started rerun as Run #')
        ->and($blocksJson)->toContain('Thread Status');
});

test('job processes task status update intent', function () {
    User::factory()->create([
        'role' => 'admin',
    ]);
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);
    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'title' => 'Polish onboarding copy',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "move task #{$task->id} to review",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($task->fresh()->status)->toBe('review');
});

test('job processes task agent run intent and attaches slack context', function () {
    User::factory()->create([
        'role' => 'admin',
    ]);
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);
    $agent = Agent::factory()->create([
        'slug' => 'dev-agent',
        'name' => 'Dev Agent',
        'status' => 'active',
    ]);
    $task = \App\Models\Task::factory()->pending()->create([
        'project_id' => $project->id,
        'title' => 'Fix checkout webhook retries',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "run dev-agent on task #{$task->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $agentTask = \App\Models\AgentTask::query()->where('task_id', $task->id)->latest()->first();

    expect($agentTask)->not->toBeNull()
        ->and($task->fresh()->status)->toBe('in_progress')
        ->and($task->fresh()->assigned_to)->toBe($agent->id)
        ->and($agentTask->context['slack']['workspace_id'])->toBe('T12345')
        ->and($agentTask->context['slack']['thread_ts'])->toBe('1234567890.123456');
});

test('job processes link context intent', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Website Refresh',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => null,
        'classification' => 'general',
        'monitoring_enabled' => false,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "link this channel to client {$client->id} project {$project->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($channel->fresh()->client_id)->toBe($client->id)
        ->and($channel->fresh()->monitoring_enabled)->toBeTrue()
        ->and($project->fresh()->slack_channel_id)->toBe($channel->id);
});

test('job processes sync github intent', function () {
    Queue::fake();

    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
        'client_id' => $client->id,
    ]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'slack_channel_id' => $channel->id,
    ]);
    $installation = GitHubInstallation::factory()->create();
    $repo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'sync github',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    Queue::assertPushed(\App\Jobs\SyncGitHubJob::class, function ($job) use ($installation, $repo) {
        return $job->installationId === $installation->id
            && $job->repoId === $repo->id;
    });
});

test('job processes show client intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create([
        'name' => 'Acme Studio',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "show client {$client->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes list projects intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
    ]);
    Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Archived Project',
        'status' => 'archived',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "list active projects for client {$client->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes create lead intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create lead Acme Prospect with website https://acme.test with email sales@acme.test',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('leads', [
        'company_name' => 'Acme Prospect',
        'contact_name' => 'Acme Prospect',
        'contact_email' => 'sales@acme.test',
    ]);
});

test('job processes list invoices intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    \App\Models\Invoice::factory()->create([
        'client_id' => $client->id,
        'status' => 'draft',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "list draft invoices for client {$client->id}",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes create website project intent', function () {
    User::factory()->create([
        'role' => 'admin',
    ]);
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create website project Acme Relaunch type autonomous domain acme.test brief Rebuild the site',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('website_projects', [
        'name' => 'Acme Relaunch',
        'project_type' => 'autonomous',
        'domain' => 'acme.test',
    ]);
});

test('job processes create client intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create client Acme Studio with website https://acme.test',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('clients', [
        'name' => 'Acme Studio',
        'website' => 'https://acme.test',
        'status' => 'active',
    ]);

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes update client intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create([
        'name' => 'Acme Studio',
        'website' => 'https://old-acme.test',
        'status' => 'prospect',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "set client {$client->id} website to https://acme.test",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($client->fresh()->website)->toBe('https://acme.test')
        ->and($context->fresh()->current_state)->toBe('idle');
});

test('job processes create project intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "create project Platform Refresh for client {$client->id} with github repo acme/platform",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('projects', [
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'github_repo' => 'acme/platform',
        'status' => 'active',
    ]);

    expect($context->fresh()->current_state)->toBe('idle');
});

test('job processes update project intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => null,
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        "mark project {$project->id} as completed",
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect($project->fresh()->status)->toBe('completed')
        ->and($context->fresh()->current_state)->toBe('idle');
});

test('job sends help for unrecognized intent', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $controlPlane = Mockery::mock(SlackControlPlaneService::class);
    $controlPlane->shouldReceive('handle')
        ->once()
        ->andReturn([
            'success' => true,
            'message' => 'Slack control plane reply',
        ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'hello there',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class),
        $controlPlane
    );

    $context->refresh();
    expect($context->current_state)->toBe('idle');
});

test('job handles missing workspace gracefully', function () {
    $job = new ProcessSlackMentionJob(
        99999,
        1,
        1,
        'test message',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    expect(true)->toBeTrue();
});

test('job extracts urgent priority from message', function () {
    User::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create a task to fix critical bug ASAP',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('tasks', [
        'title' => 'fix critical bug ASAP',
        'priority' => 'urgent',
    ]);
});

test('job extracts high priority from message', function () {
    User::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        'create task to review PR with high priority',
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $this->assertDatabaseHas('tasks', [
        'priority' => 'high',
    ]);
});

test('job detects trigger intent with various phrasings', function (string $message, string $expectedSlug) {
    $workspace = SlackWorkspace::factory()->create();
    Agent::factory()->create(['slug' => $expectedSlug, 'status' => 'active']);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'pending_actions' => [],
    ]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        $message,
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->pending_actions)->toHaveCount(1)
        ->and($context->pending_actions[0]['data']['agent_slug'])->toBe($expectedSlug);
})->with([
    'run phrasing' => ['run the code-review agent', 'code-review'],
    'trigger phrasing' => ['trigger the analysis agent', 'analysis'],
    'run with task' => ['run dev-agent on the auth module', 'dev-agent'],
]);

test('job detects search intent with various phrasings', function (string $message) {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $job = new ProcessSlackMentionJob(
        $workspace->id,
        $channel->id,
        $context->id,
        $message,
        'U12345'
    );

    $job->handle(
        app(SlackMentionOrchestrator::class),
        app(SlackBotResponseService::class)
    );

    $context->refresh();
    expect($context->current_state)->toBe('idle');
})->with([
    'find' => ['find all tasks about api'],
    'search' => ['search for projects related to mobile'],
    'look for' => ['look for tasks containing bug'],
    'show me' => ['show me tasks about authentication'],
]);

test('job is queued on slack-mentions queue', function () {
    Queue::fake();

    $job = new ProcessSlackMentionJob(1, 1, 1, 'test', 'U12345');

    Queue::push($job);

    Queue::assertPushed(ProcessSlackMentionJob::class, function ($job) {
        return $job->queue === 'slack-mentions';
    });
});
