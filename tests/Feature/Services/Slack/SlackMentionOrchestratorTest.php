<?php

use App\Jobs\ProcessSlackMentionJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Models\User;
use App\Services\Agents\CompoundEngineeringSkillLoader;
use App\Services\CapabilitySynthesisService;
use App\Services\Slack\SlackAgentRunService;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use App\Services\Slack\SlackEngineeringAgentService;
use App\Services\Slack\SlackEngineeringApprovalService;
use App\Services\Slack\SlackGitHubEngineeringActionService;
use App\Services\Slack\SlackIntentDetectionService;
use App\Services\Slack\SlackLinkedProjectService;
use App\Services\Slack\SlackMcpToolBridge;
use App\Services\Slack\SlackMentionOrchestrator;
use App\Services\Slack\SlackStagingThreadService;
use App\Services\Slack\SlackStagingWorkflowService;
use App\Services\Slack\SlackThreadRunService;
use App\Services\Slack\SlackWatchlistService;
use App\Services\TaskAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->apiService = Mockery::mock(SlackApiService::class);
    $this->responseService = new SlackBotResponseService;
    $this->skillLoader = Mockery::mock(CompoundEngineeringSkillLoader::class);
    $this->skillLoader->shouldReceive('parseInvocation')
        ->andReturn(['matched' => false])
        ->byDefault();
    $this->intentDetection = new SlackIntentDetectionService($this->skillLoader);
    $this->capabilitySynthesis = new CapabilitySynthesisService;
    $this->engineeringAgentService = Mockery::mock(SlackEngineeringAgentService::class);
    $this->engineeringApprovalService = Mockery::mock(SlackEngineeringApprovalService::class);
    $this->agentRunService = Mockery::mock(SlackAgentRunService::class);
    $this->taskAgentService = Mockery::mock(TaskAgentService::class);
    $this->gitHubEngineeringActionService = Mockery::mock(SlackGitHubEngineeringActionService::class);
    $this->linkedProjectService = Mockery::mock(SlackLinkedProjectService::class);
    $this->threadRunService = Mockery::mock(SlackThreadRunService::class);
    $this->mcpBridge = Mockery::mock(SlackMcpToolBridge::class);
    $this->stagingWorkflowService = Mockery::mock(SlackStagingWorkflowService::class);
    $this->stagingWorkflowService->shouldReceive('describeChannelStaging')
        ->andReturn(['error' => 'No staging workflow configured.'])
        ->byDefault();
    $this->stagingThreadService = Mockery::mock(SlackStagingThreadService::class);
    $this->stagingThreadService->shouldReceive('rememberPublishedThread')
        ->byDefault();
    $this->watchlistService = new SlackWatchlistService;
    $this->orchestrator = new SlackMentionOrchestrator(
        $this->apiService,
        $this->responseService,
        $this->skillLoader,
        $this->intentDetection,
        $this->capabilitySynthesis,
        $this->engineeringAgentService,
        $this->engineeringApprovalService,
        $this->agentRunService,
        $this->taskAgentService,
        $this->gitHubEngineeringActionService,
        $this->linkedProjectService,
        $this->threadRunService,
        $this->mcpBridge,
        $this->stagingWorkflowService,
        $this->stagingThreadService,
        $this->watchlistService,
    );
});

test('isBotMentioned returns true when bot is mentioned', function () {
    $botUserId = 'U12345BOT';

    expect($this->orchestrator->isBotMentioned('<@U12345BOT> hello', $botUserId))->toBeTrue()
        ->and($this->orchestrator->isBotMentioned('Hello <@U12345BOT> world', $botUserId))->toBeTrue()
        ->and($this->orchestrator->isBotMentioned('<@U12345BOT>', $botUserId))->toBeTrue();
});

test('isBotMentioned returns false when bot is not mentioned', function () {
    $botUserId = 'U12345BOT';

    expect($this->orchestrator->isBotMentioned('hello world', $botUserId))->toBeFalse()
        ->and($this->orchestrator->isBotMentioned('<@UOTHER>', $botUserId))->toBeFalse()
        ->and($this->orchestrator->isBotMentioned('', $botUserId))->toBeFalse();
});

test('isBotMentioned returns false when botUserId is null', function () {
    expect($this->orchestrator->isBotMentioned('<@U12345BOT> hello', null))->toBeFalse();
});

test('handleMention dispatches job for non-empty message', function () {
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U12345BOT']);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturn(['ok' => true]);

    $event = [
        'text' => '<@U12345BOT> create a task to fix the bug',
        'user' => 'U67890',
        'ts' => '1234567890.123456',
    ];

    $this->orchestrator->handleMention($workspace, $channel, $event);

    Queue::assertPushed(ProcessSlackMentionJob::class, function ($job) use ($workspace, $channel) {
        return $job->workspaceId === $workspace->id
            && $job->channelId === $channel->id
            && $job->userMessage === 'create a task to fix the bug'
            && $job->userId === 'U67890';
    });

    $this->assertDatabaseHas('slack_thread_contexts', [
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);
});

test('handleMention sends help message for empty mention', function () {
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U12345BOT']);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with($workspace, $channel->channel_id, '', Mockery::on(function ($options) {
            return isset($options['blocks'])
                && str_contains(json_encode($options['blocks']), 'I can help you with');
        }))
        ->andReturn(['ok' => true]);

    $event = [
        'text' => '<@U12345BOT>',
        'user' => 'U67890',
        'ts' => '1234567890.123456',
    ];

    $this->orchestrator->handleMention($workspace, $channel, $event);

    Queue::assertNotPushed(ProcessSlackMentionJob::class);
});

test('handleMention preserves thread context', function () {
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U12345BOT']);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

    $this->apiService->shouldReceive('postMessage')->andReturn(['ok' => true]);

    $event = [
        'text' => '<@U12345BOT> first message',
        'user' => 'U67890',
        'ts' => '1234567890.222222',
        'thread_ts' => '1234567890.111111',
    ];

    $this->orchestrator->handleMention($workspace, $channel, $event);

    $context = SlackThreadContext::where('thread_ts', '1234567890.111111')->first();
    expect($context)->not->toBeNull()
        ->and($context->channel_id)->toBe($channel->id)
        ->and($context->conversation_history)->toHaveCount(1)
        ->and($context->conversation_history[0]['role'])->toBe('user')
        ->and($context->conversation_history[0]['content'])->toBe('first message');
});

test('handleDirectMessage dispatches job without requiring a mention', function () {
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U12345BOT']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'is_dm' => true,
        'monitoring_enabled' => true,
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturn(['ok' => true]);

    $event = [
        'text' => 'what should I work on today?',
        'user' => 'U67890',
        'ts' => '1234567890.333333',
    ];

    $this->orchestrator->handleDirectMessage($workspace, $channel, $event);

    Queue::assertPushed(ProcessSlackMentionJob::class, function ($job) use ($workspace, $channel) {
        return $job->workspaceId === $workspace->id
            && $job->channelId === $channel->id
            && $job->userMessage === 'what should I work on today?'
            && $job->userId === 'U67890';
    });

    $context = SlackThreadContext::where('channel_id', $channel->id)
        ->where('thread_ts', '1234567890.333333')
        ->first();

    expect($context)->not->toBeNull()
        ->and($context->current_state)->toBe('idle')
        ->and($context->context_data['invoked_via'] ?? null)->toBe('direct_message')
        ->and($context->conversation_history[0]['content'])->toBe('what should I work on today?');
});

test('getMessageContext returns message with history and channel context', function () {
    $client = Client::factory()->create(['name' => 'Test Client']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'owner/repo',
    ]);
    $channel = SlackChannel::factory()->create(['client_id' => $client->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'conversation_history' => [
            ['role' => 'user', 'content' => 'Previous message', 'timestamp' => now()->subMinutes(5)->toIso8601String()],
        ],
    ]);

    $result = $this->orchestrator->getMessageContext($context, 'New user message');

    expect($result['message'])->toBe('New user message')
        ->and($result['history'])->toContain('user: Previous message')
        ->and($result['channel_context']['channel_name'])->toBe($channel->channel_name)
        ->and($result['channel_context']['client']['name'])->toBe('Test Client')
        ->and($result['channel_context']['project']['github_repo'])->toBe('owner/repo');
});

test('executeAction handles watchlist tracking', function () {
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U12345BOT']);
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

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'manage_watchlist',
        'operation' => 'track',
        'query' => 'acme-client',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['operation'])->toBe('track')
        ->and($result['items'])->toHaveCount(1);

    $this->assertDatabaseHas('slack_user_watchlist_items', [
        'workspace_id' => $workspace->id,
        'slack_user_id' => 'U12345',
        'slack_channel_id' => $channel->id,
        'is_active' => true,
    ]);
});

test('executeAction handles create_task action', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create(['client_id' => $client->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'create_task',
        'title' => 'Fix the authentication bug',
        'priority' => 'high',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeTrue()
        ->and($result['task_id'])->toBeInt()
        ->and($result['message'])->toContain('Fix the authentication bug');

    $this->assertDatabaseHas('tasks', [
        'title' => 'Fix the authentication bug',
        'project_id' => $project->id,
        'priority' => 'high',
        'source' => 'manual',
    ]);
});

test('executeAction create_task fails without linked project', function () {
    $channel = SlackChannel::factory()->create(['client_id' => null]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'create_task',
        'title' => 'Some task',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('No active project');
});

test('executeAction remembers task context for thread summaries', function () {
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'name' => 'Platform Refresh',
    ]);
    $channel = SlackChannel::factory()->create(['client_id' => $client->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_task',
        'title' => 'Document deploy flow',
    ]);

    $context->refresh();

    expect($result['success'])->toBeTrue()
        ->and($context->context_data['task_id'])->toBe($result['task_id'])
        ->and($context->context_data['project_id'])->toBe($project->id)
        ->and($context->context_data['client_id'])->toBe($client->id);
});

test('executeAction handles log_note action', function () {
    User::factory()->create();
    $client = Client::factory()->create();
    $channel = SlackChannel::factory()->create(['client_id' => $client->id]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'log_note',
        'content' => 'Client approved the design mockup',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeTrue()
        ->and($result['note_id'])->toBeInt()
        ->and($result['message'])->toContain($client->name);

    $this->assertDatabaseHas('client_notes', [
        'content' => 'Client approved the design mockup',
        'client_id' => $client->id,
    ]);
});

test('executeAction log_note fails without linked client', function () {
    $channel = SlackChannel::factory()->create(['client_id' => null]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'log_note',
        'content' => 'Some note',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('No client');
});

test('executeAction handles trigger_agent action', function () {
    Queue::fake();
    $agent = Agent::factory()->create([
        'slug' => 'dev-agent',
        'status' => 'active',
        'circuit_broken_at' => null,
    ]);
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
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
        'context_data' => [
            'slack_user_id' => 'U12345',
        ],
    ]);

    $this->linkedProjectService->shouldReceive('resolve')
        ->once()
        ->with(\Mockery::on(fn ($candidate) => $candidate->is($channel)))
        ->andReturn(['project' => $project]);

    $action = [
        'type' => 'trigger_agent',
        'agent_slug' => 'dev-agent',
        'task' => 'Fix the bug in auth module',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBeInt()
        ->and($result['message'])->toContain($agent->name);

    $this->assertDatabaseHas('agent_runs', [
        'agent_id' => $agent->id,
        'task' => 'Fix the bug in auth module',
        'invocation_source' => 'slack',
        'status' => 'running',
        'project_id' => $project->id,
        'invoked_by' => 'U12345',
    ]);
});

test('executeAction handles trigger_agent action with interactive runner and linked project context', function () {
    Queue::fake();

    $agent = Agent::factory()->create([
        'slug' => 'compound-engineering',
        'status' => 'active',
        'circuit_broken_at' => null,
    ]);
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create();
    Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    $linkedProject = Project::factory()->create([
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
        'context_data' => [
            'slack_user_id' => 'U99999',
        ],
    ]);

    $this->linkedProjectService->shouldReceive('resolve')
        ->once()
        ->with(\Mockery::on(fn ($candidate) => $candidate->is($channel)))
        ->andReturn(['project' => $linkedProject]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'trigger_agent',
        'agent_slug' => 'compound-engineering',
        'task' => 'Plan the deployment workflow',
    ]);

    expect($result['success'])->toBeTrue();

    $run = AgentRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->project_id)->toBe($linkedProject->id)
        ->and($run->invoked_by)->toBe('U99999')
        ->and(data_get($run->context, 'client.id'))->toBe($client->id)
        ->and(data_get($run->context, 'project.id'))->toBe($linkedProject->id);

    Queue::assertPushed(\App\Jobs\RunInteractiveAgentJob::class, function ($job) use ($run) {
        return $job->run->is($run);
    });

    Queue::assertNotPushed(\App\Jobs\RunAgentJob::class);
});

test('executeAction trigger_agent fails for inactive agent', function () {
    Agent::factory()->create([
        'slug' => 'inactive-agent',
        'status' => 'disabled',
    ]);
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'trigger_agent',
        'agent_slug' => 'inactive-agent',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('not found or not active');
});

test('executeAction trigger_agent fails for circuit broken agent', function () {
    Agent::factory()->create([
        'slug' => 'broken-agent',
        'status' => 'active',
        'circuit_broken_at' => now(),
    ]);
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'trigger_agent',
        'agent_slug' => 'broken-agent',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('unavailable');
});

test('executeAction handles search action', function () {
    Task::factory()->create(['title' => 'Authentication flow redesign']);
    Project::factory()->create(['name' => 'Authentication Project']);
    Client::factory()->create(['name' => 'Auth Corp']);

    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = [
        'type' => 'search',
        'query' => 'auth',
        'search_type' => 'all',
    ];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeTrue()
        ->and($result['results']['tasks'])->toHaveCount(1)
        ->and($result['results']['projects'])->toHaveCount(1)
        ->and($result['results']['clients'])->toHaveCount(1);
});

test('executeAction handles get_status action', function () {
    $client = Client::factory()->create(['health_score' => 85]);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'in_progress',
    ]);

    $channel = SlackChannel::factory()->create([
        'client_id' => $client->id,
        'monitoring_enabled' => true,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = ['type' => 'get_status'];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeTrue()
        ->and($result['status']['monitoring'])->toBeTrue()
        ->and($result['status']['client']['name'])->toBe($client->name)
        ->and($result['status']['projects'])->toHaveCount(1)
        ->and($result['status']['projects'][0]['pending_tasks'])->toBe(1)
        ->and($result['status']['projects'][0]['in_progress_tasks'])->toBe(1);
});

test('executeAction handles get_focus action', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_PENDING_APPROVAL,
    ]);

    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'status' => 'pending',
        'risk_level' => 'high',
    ]);

    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'get_focus',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['briefing']['summary']['critical'])->toBeGreaterThan(0)
        ->and($result['briefing']['top_priorities'])->not->toBeEmpty()
        ->and($result['message'])->toContain('focus briefing');
});

test('executeAction returns error for unknown action type', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $action = ['type' => 'unknown_action'];

    $result = $this->orchestrator->executeAction($context, $action);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Unknown action type');
});

test('executeAction handles get_channel_context action', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'workspace' => ['name' => 'Workspace'],
            'channel' => ['name' => 'ops'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'get_channel_context',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['workspace']['name'])->toBe('Workspace');
});

test('executeAction handles get_thread_summary action', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Fix checkout flow',
        'status' => 'review',
        'priority' => 'high',
    ]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
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
            'branch' => 'fix/checkout-flow',
            'staging_url' => 'https://staging.acme.test',
            'deployment_status' => 'deployed',
        ],
    ]);
    ApprovalRequest::factory()->create([
        'agent_run_id' => $run->id,
        'description' => 'Approve staging deploy',
        'status' => 'pending',
        'risk_level' => 'high',
    ]);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id,
        'number' => 'INV-2042',
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'agent_run_id' => $run->id,
        'context_data' => [
            'task_id' => $task->id,
            'invoice_id' => $invoice->id,
            'website_project_id' => 9,
        ],
    ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => $channel->channel_id,
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-website-project', [
            'id' => 9,
        ])
        ->andReturn([
            'id' => 9,
            'name' => 'Acme Relaunch',
            'status' => 'building',
            'project_type' => 'autonomous',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'get_thread_summary',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run']['id'])->toBe($run->id)
        ->and($result['run']['issue_number'])->toBe(42)
        ->and($result['task']['id'])->toBe($task->id)
        ->and($result['approvals'])->toHaveCount(1)
        ->and($result['invoice']['number'])->toBe('INV-2042')
        ->and($result['website_project']['name'])->toBe('Acme Relaunch');
});

test('executeAction handles request_review_deploy action and refreshes the thread summary', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
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

    $this->engineeringApprovalService->shouldReceive('requestReviewDeploy')
        ->once()
        ->with(Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id))
        ->andReturn([
            'success' => true,
            'message' => 'Requested review deploy for PR #17 on `feature/fix-42`.',
        ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'request_review_deploy',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($run->id)
        ->and($result['message'])->toBe('Requested review deploy for PR #17 on `feature/fix-42`.')
        ->and($result['thread_summary']['run']['id'])->toBe($run->id)
        ->and($result['thread_summary']['run']['deployment_status'])->toBe('queued');
});

test('executeAction handles retry_review_deploy action and refreshes the thread summary', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
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
            'deployment_status' => 'failed',
            'workflow_status' => 'completed',
            'workflow_run_id' => 9001,
        ],
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
    ]);

    $this->engineeringApprovalService->shouldReceive('retryReviewDeploy')
        ->once()
        ->with(Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id))
        ->andReturn([
            'success' => true,
            'message' => 'Retried review deploy for PR #17.',
        ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'retry_review_deploy',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($run->id)
        ->and($result['message'])->toBe('Retried review deploy for PR #17.')
        ->and($result['thread_summary']['run']['id'])->toBe($run->id)
        ->and($result['thread_summary']['run']['deployment_status'])->toBe('failed');
});

test('executeAction handles retry_agent_run action and refreshes the thread summary', function () {
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
    $newRun = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'task' => 'Investigate checkout issue',
        'status' => AgentRun::STATUS_RUNNING,
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

    $this->threadRunService->shouldReceive('restartRun')
        ->once()
        ->with(
            Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id),
            'U12345',
            'T12345',
            'C12345',
            Mockery::on(fn (SlackThreadContext $candidate) => $candidate->id === $context->id)
        )
        ->andReturnUsing(function () use ($context, $newRun) {
            $context->update([
                'agent_run_id' => $newRun->id,
                'context_data' => array_merge($context->context_data ?? [], [
                    'agent_run_id' => $newRun->id,
                ]),
            ]);

            return $newRun;
        });

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'channel' => ['name' => 'eng'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'retry_agent_run',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($newRun->id)
        ->and($result['thread_summary']['run']['id'])->toBe($newRun->id);
});

test('executeAction handles cancel_agent_run action and refreshes the thread summary', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => AgentRun::STATUS_RUNNING,
        'task' => 'Investigate checkout issue',
        'invocation_source' => AgentRun::SOURCE_SLACK,
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
        'error_message' => null,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'context_data' => [
            'slack_user_id' => 'U12345',
        ],
    ]);

    $this->agentRunService->shouldReceive('isCancellable')
        ->once()
        ->with(Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id))
        ->andReturn(true);

    $this->agentRunService->shouldReceive('cancelRun')
        ->once()
        ->with(
            Mockery::on(fn (AgentRun $candidate) => $candidate->id === $run->id),
            'Cancelled from Slack by U12345'
        )
        ->andReturnUsing(function () use ($run) {
            $run->update([
                'status' => AgentRun::STATUS_CANCELLED,
                'error_message' => 'Cancelled from Slack by U12345',
                'completed_at' => now(),
            ]);

            return $run->fresh();
        });

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'cancel_agent_run',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($run->id)
        ->and($result['message'])->toBe('Cancelled Run #'.$run->id.'.')
        ->and($result['thread_summary']['run']['id'])->toBe($run->id)
        ->and($result['thread_summary']['run']['status'])->toBe(AgentRun::STATUS_CANCELLED);
});

test('executeAction passes source pull request context into engineering runs', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->active()->create([
        'name' => 'Dev Agent',
        'slug' => 'dev-agent',
    ]);
    $sourceRun = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'channel_id' => 'C12345',
            ],
            'engineering' => [
                'repo' => 'acme/platform',
            ],
        ],
        'output' => [
            'pr_number' => 17,
            'pr_url' => 'https://github.com/acme/platform/pull/17',
        ],
    ]);
    $newRun = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'project_id' => $project->id,
        'status' => AgentRun::STATUS_RUNNING,
        'invocation_source' => AgentRun::SOURCE_SLACK,
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $sourceRun->id,
        'context_data' => [
            'slack_user_id' => 'U12345',
            'project_id' => $project->id,
            'project_repo' => 'acme/platform',
        ],
    ]);

    $this->engineeringAgentService->shouldReceive('startIssueRun')
        ->once()
        ->withArgs(function (
            $workspaceArg,
            $channelArg,
            $slackUserId,
            $threadTs,
            $issueNumber,
            $deliveryTarget,
            $branchPreference,
            $requestText,
            $sourcePrNumber,
            $sourcePrUrl,
        ) use ($workspace, $channel) {
            return $workspaceArg->id === $workspace->id
                && $channelArg->id === $channel->id
                && $slackUserId === 'U12345'
                && $threadTs === '1234567890.123456'
                && $issueNumber === 42
                && $deliveryTarget === 'pr'
                && $branchPreference === null
                && $requestText === 'work issue #42 from this PR'
                && $sourcePrNumber === 17
                && $sourcePrUrl === 'https://github.com/acme/platform/pull/17';
        })
        ->andReturn([
            'success' => true,
            'run' => $newRun,
            'message' => 'Started Dev Agent on issue #42.',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'trigger_engineering_agent',
        'issue_number' => 42,
        'delivery_target' => 'pr',
        'task' => 'work issue #42 from this PR',
        'use_thread_pr' => true,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($newRun->id);
});

test('executeAction handles request_review_deploy action for a referenced pr number', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
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
            'deployment_status' => 'deployed',
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
            'deployment_status' => 'queued',
            'workflow_status' => 'requested',
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

    $this->engineeringApprovalService->shouldReceive('requestReviewDeploy')
        ->once()
        ->with(Mockery::on(fn (AgentRun $candidate) => $candidate->id === $targetRun->id))
        ->andReturn([
            'success' => true,
            'message' => 'Requested review deploy for PR #17 on `feature/fix-17`.',
        ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'request_review_deploy',
        'pr_number' => 17,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run_id'])->toBe($targetRun->id)
        ->and($result['thread_summary']['run']['id'])->toBe($targetRun->id)
        ->and($result['thread_summary']['requested_pr_number'])->toBe(17);
});

test('executeAction handles focused workflow summary for a referenced pr number', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
        'github_repo' => 'acme/platform',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $agent = Agent::factory()->create([
        'name' => 'Dev Agent',
    ]);
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
            'workflow_url' => 'https://github.com/acme/platform/actions/runs/321',
            'workflow_status' => 'in_progress',
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

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'get_thread_summary',
        'focus' => 'workflow',
        'pr_number' => 17,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['focus'])->toBe('workflow')
        ->and($result['requested_pr_number'])->toBe(17)
        ->and($result['run']['id'])->toBe($targetRun->id)
        ->and($result['run']['workflow_status'])->toBe('in_progress');
});

test('executeAction handles prepare staging secret and remembers staging context', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $client = Client::factory()->create(['name' => 'Acme']);
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
        'status' => 'active',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
        'channel_id' => 'C12345',
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $this->stagingWorkflowService->shouldReceive('describeChannelStaging')
        ->once()
        ->with('T12345', 'C12345')
        ->andReturn([
            'project' => ['id' => $project->id, 'name' => 'Platform Refresh'],
            'client' => ['id' => $client->id, 'name' => 'Acme'],
            'repo' => ['full_name' => 'acme/platform'],
            'required_secrets' => [
                ['name' => 'VERCEL_TOKEN', 'sync_status' => 'missing', 'target_scope' => 'environment'],
            ],
            'summary' => ['required' => 1, 'configured' => 0, 'synced' => 0, 'missing' => 1, 'pending_sync' => 0],
            'deployment' => ['workflow_identifier' => 'deploy.yml', 'publish_branch' => 'main'],
            'ready_to_publish' => false,
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'prepare_staging_secret',
        'secret_name' => 'VERCEL_TOKEN',
    ]);

    $context->refresh();

    expect($result['success'])->toBeTrue()
        ->and($result['prefill_secret_name'])->toBe('VERCEL_TOKEN')
        ->and($result['message'])->toContain('securely add `VERCEL_TOKEN`')
        ->and($context->context_data['project_id'])->toBe($project->id)
        ->and($context->context_data['client_id'])->toBe($client->id)
        ->and($context->context_data['project_repo'])->toBe('acme/platform')
        ->and($context->context_data['staging_secret_name'])->toBe('VERCEL_TOKEN');
});

test('executeAction handles publish staging', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
    ]);

    $this->engineeringApprovalService->shouldReceive('publishToStaging')
        ->once()
        ->with('T12345', 'C12345', Mockery::on(fn (SlackThreadContext $candidate) => $candidate->id === $context->id))
        ->andReturn([
            'project' => ['id' => 12, 'name' => 'Platform Refresh'],
            'client' => ['id' => 8, 'name' => 'Acme'],
            'repo' => ['id' => 33, 'full_name' => 'acme/platform'],
            'deployment' => ['workflow_identifier' => 'deploy.yml', 'publish_branch' => 'main', 'staging_url' => 'https://staging.acme.test'],
            'summary' => ['required' => 1, 'configured' => 1, 'synced' => 1, 'missing' => 0, 'pending_sync' => 0],
            'required_secrets' => [],
            'ready_to_publish' => true,
            'publish' => ['workflow_identifier' => 'deploy.yml', 'branch' => 'main'],
            'message' => 'Queued staging publish via `deploy.yml` on `main`.',
        ]);
    $this->stagingThreadService->shouldReceive('rememberPublishedThread')
        ->once()
        ->with(
            Mockery::on(fn (SlackThreadContext $candidate) => $candidate->id === $context->id),
            Mockery::on(fn (array $result) => ($result['repo']['full_name'] ?? null) === 'acme/platform')
        );

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'publish_staging',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain('Queued staging publish');
});

test('executeAction handles list agent runs for the linked project', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
        'name' => 'Platform Refresh',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);
    $run = AgentRun::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->linkedProjectService->shouldReceive('resolve')
        ->once()
        ->with(Mockery::on(fn (SlackChannel $candidate) => $candidate->id === $channel->id))
        ->andReturn(['project' => $project]);
    $this->agentRunService->shouldReceive('normalizeFilter')
        ->once()
        ->with('active')
        ->andReturn('active');
    $this->agentRunService->shouldReceive('listProjectRuns')
        ->once()
        ->with(Mockery::on(fn (Project $candidate) => $candidate->id === $project->id), 'active')
        ->andReturn(new \Illuminate\Database\Eloquent\Collection([$run]));

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_agent_runs',
        'filter' => 'active',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['project']['id'])->toBe($project->id)
        ->and($result['runs'])->toHaveCount(1)
        ->and($result['filter'])->toBe('active');
});

test('executeAction handles show agent run for the linked project', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $client->id,
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'client_id' => $client->id,
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);
    $run = AgentRun::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->linkedProjectService->shouldReceive('resolve')
        ->once()
        ->with(Mockery::on(fn (SlackChannel $candidate) => $candidate->id === $channel->id))
        ->andReturn(['project' => $project]);
    $this->agentRunService->shouldReceive('findProjectRun')
        ->once()
        ->with(Mockery::on(fn (Project $candidate) => $candidate->id === $project->id), $run->id)
        ->andReturn($run);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'show_agent_run',
        'run_id' => $run->id,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['run']->id)->toBe($run->id)
        ->and($context->fresh()->context_data['agent_run_id'])->toBe($run->id);
});

test('executeAction handles link_context action', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('link-slack-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
            'client_id' => 12,
            'project_id' => 34,
            'monitoring_enabled' => true,
        ])
        ->andReturn([
            'message' => 'Linked Slack channel ops to Acme / Website.',
        ]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-channel-operations-context', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
        ])
        ->andReturn([
            'workspace' => ['name' => 'Workspace'],
            'channel' => ['name' => 'ops'],
            'client' => ['name' => 'Acme'],
            'project' => ['name' => 'Website'],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'link_context',
        'client_id' => 12,
        'project_id' => 34,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['context']['client']['name'])->toBe('Acme');
});

test('executeAction handles run_integration_sync action', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('run-channel-integration-action', [
            'workspace_id' => 'T12345',
            'channel_id' => 'C12345',
            'action' => 'sync-github',
        ])
        ->andReturn([
            'success' => true,
            'message' => 'Queued GitHub sync.',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'run_integration_sync',
        'target' => 'github',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain('Queued GitHub sync');
});

test('executeAction handles show_client action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-client', [
            'id' => 12,
        ])
        ->andReturn([
            'id' => 12,
            'name' => 'Acme Studio',
            'status' => 'active',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'show_client',
        'id' => 12,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('Acme Studio');
});

test('executeAction handles list_clients action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('list-clients', [
            'status' => 'active',
            'limit' => 10,
        ])
        ->andReturn([
            'clients' => [
                ['id' => 12, 'name' => 'Acme Studio'],
            ],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_clients',
        'status' => 'active',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['clients'])->toHaveCount(1);
});

test('executeAction handles create_client action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('create-client', [
            'name' => 'Acme Studio',
            'website' => 'https://acme.test',
        ])
        ->andReturn([
            'id' => 12,
            'name' => 'Acme Studio',
            'status' => 'active',
            'message' => "Client 'Acme Studio' created successfully.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_client',
        'name' => 'Acme Studio',
        'website' => 'https://acme.test',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['id'])->toBe(12)
        ->and($result['message'])->toContain('created successfully');
});

test('executeAction handles update_client action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('update-client', [
            'id' => 12,
            'name' => 'Acme Labs',
        ])
        ->andReturn([
            'id' => 12,
            'name' => 'Acme Labs',
            'status' => 'active',
            'message' => "Client 'Acme Labs' updated successfully.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'update_client',
        'id' => 12,
        'name' => 'Acme Labs',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('Acme Labs')
        ->and($result['message'])->toContain('updated successfully');
});

test('executeAction handles show_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-project', [
            'id' => 34,
        ])
        ->andReturn([
            'id' => 34,
            'name' => 'Platform Refresh',
            'status' => 'active',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'show_project',
        'id' => 34,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('Platform Refresh');
});

test('executeAction handles list_projects action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('list-projects', [
            'client_id' => 12,
            'status' => 'active',
            'limit' => 10,
        ])
        ->andReturn([
            'projects' => [
                ['id' => 34, 'name' => 'Platform Refresh'],
            ],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_projects',
        'client_id' => 12,
        'status' => 'active',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['projects'])->toHaveCount(1);
});

test('executeAction handles list_leads action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('list-leads', [
            'stage' => 'qualified',
            'limit' => 10,
        ])
        ->andReturn([
            'leads' => [
                ['id' => 7, 'company_name' => 'Acme Prospect'],
            ],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_leads',
        'stage' => 'qualified',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['leads'])->toHaveCount(1);
});

test('executeAction handles create_lead action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('create-lead', [
            'company_name' => 'Acme Prospect',
            'contact_name' => 'Acme Prospect',
            'website' => 'https://acme.test',
            'contact_email' => 'sales@acme.test',
        ])
        ->andReturn([
            'id' => 7,
            'company_name' => 'Acme Prospect',
            'stage' => 'new',
            'message' => "Lead 'Acme Prospect' created successfully.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_lead',
        'company_name' => 'Acme Prospect',
        'contact_name' => 'Acme Prospect',
        'website' => 'https://acme.test',
        'contact_email' => 'sales@acme.test',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['company_name'])->toBe('Acme Prospect');
});

test('executeAction handles update_lead_stage action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('update-lead-stage', [
            'id' => 7,
            'stage' => 'proposal',
        ])
        ->andReturn([
            'id' => 7,
            'company_name' => 'Acme Prospect',
            'new_stage' => 'proposal',
            'message' => "Lead 'Acme Prospect' moved from new to proposal.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'update_lead_stage',
        'id' => 7,
        'stage' => 'proposal',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['new_stage'])->toBe('proposal');
});

test('executeAction handles list_invoices action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('list-invoices', [
            'client_id' => 12,
            'status' => 'draft',
            'limit' => 10,
        ])
        ->andReturn([
            'invoices' => [
                ['id' => 3, 'number' => 'INV-1'],
            ],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_invoices',
        'client_id' => 12,
        'status' => 'draft',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['invoices'])->toHaveCount(1);
});

test('executeAction handles create_invoice action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $items = [[
        'description' => 'Homepage redesign',
        'quantity' => 2.0,
        'unit_price' => 2500.0,
        'type' => 'fixed',
    ]];

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('create-invoice', [
            'client_id' => 12,
            'subject' => 'Homepage redesign',
            'items' => $items,
        ])
        ->andReturn([
            'id' => 3,
            'number' => 'INV-1',
            'message' => 'Invoice created.',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_invoice',
        'client_id' => 12,
        'subject' => 'Homepage redesign',
        'items' => $items,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['number'])->toBe('INV-1');
});

test('executeAction handles show_website_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('get-website-project', [
            'id' => 9,
        ])
        ->andReturn([
            'id' => 9,
            'name' => 'Acme Relaunch',
            'status' => 'building',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'show_website_project',
        'id' => 9,
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('Acme Relaunch');
});

test('executeAction handles list_website_projects action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('list-website-projects', [
            'status' => 'building',
            'project_type' => 'autonomous',
            'limit' => 10,
        ])
        ->andReturn([
            'website_projects' => [
                ['id' => 9, 'name' => 'Acme Relaunch'],
            ],
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'list_website_projects',
        'status' => 'building',
        'project_type' => 'autonomous',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['website_projects'])->toHaveCount(1);
});

test('executeAction handles create_website_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('create-website-project', [
            'name' => 'Acme Relaunch',
            'project_type' => 'autonomous',
            'domain' => 'acme.test',
            'brief' => 'Rebuild the site',
            'source_type' => 'brief',
        ])
        ->andReturn([
            'id' => 9,
            'name' => 'Acme Relaunch',
            'status' => 'created',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_website_project',
        'name' => 'Acme Relaunch',
        'project_type' => 'autonomous',
        'domain' => 'acme.test',
        'brief' => 'Rebuild the site',
        'source_type' => 'brief',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('Acme Relaunch');
});

test('executeAction handles update_website_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('update-website-project', [
            'id' => 9,
            'status' => 'building',
        ])
        ->andReturn([
            'project_id' => 9,
            'project_name' => 'Acme Relaunch',
            'current_status' => 'building',
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'update_website_project',
        'id' => 9,
        'status' => 'building',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['current_status'])->toBe('building');
});

test('executeAction handles create_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('create-project', [
            'name' => 'Platform Refresh',
            'client_id' => 12,
            'github_repo' => 'acme/platform',
        ])
        ->andReturn([
            'id' => 34,
            'name' => 'Platform Refresh',
            'status' => 'active',
            'message' => "Project 'Platform Refresh' created for client 'Acme'.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'create_project',
        'name' => 'Platform Refresh',
        'client_id' => 12,
        'github_repo' => 'acme/platform',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['id'])->toBe(34)
        ->and($result['message'])->toContain('Platform Refresh');
});

test('executeAction handles update_project action', function () {
    $channel = SlackChannel::factory()->create();
    $context = SlackThreadContext::factory()->create(['channel_id' => $channel->id]);

    $this->mcpBridge->shouldReceive('execute')
        ->once()
        ->with('update-project', [
            'id' => 34,
            'github_repo' => 'acme/platform',
        ])
        ->andReturn([
            'id' => 34,
            'name' => 'Platform Refresh',
            'status' => 'active',
            'message' => "Project 'Platform Refresh' updated successfully.",
        ]);

    $result = $this->orchestrator->executeAction($context, [
        'type' => 'update_project',
        'id' => 34,
        'github_repo' => 'acme/platform',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['id'])->toBe(34)
        ->and($result['message'])->toContain('updated successfully');
});

test('sendResponse posts message and updates history', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'conversation_history' => [],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::on(fn ($ws) => $ws->id === $workspace->id),
            $channel->channel_id,
            'Test response',
            Mockery::on(fn ($options) => $options['thread_ts'] === '1234567890.123456')
        )
        ->andReturn(['ok' => true]);

    $this->orchestrator->sendResponse($context, 'Test response');

    $context->refresh();
    expect($context->conversation_history)->toHaveCount(1)
        ->and($context->conversation_history[0]['role'])->toBe('assistant')
        ->and($context->conversation_history[0]['content'])->toBe('Test response');
});

test('sendBlockResponse posts blocks to thread', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $context = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
    ]);

    $blocks = [
        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Test']],
    ];

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::on(fn ($ws) => $ws->id === $workspace->id),
            $channel->channel_id,
            '',
            Mockery::on(fn ($options) => $options['thread_ts'] === '1234567890.123456' && $options['blocks'] === $blocks)
        )
        ->andReturn(['ok' => true]);

    $this->orchestrator->sendBlockResponse($context, $blocks);
});
