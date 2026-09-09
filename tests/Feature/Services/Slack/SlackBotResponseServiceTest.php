<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\Task;
use App\Models\User;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SlackBotResponseService;
});

test('errorResponse returns ephemeral response with message', function () {
    $response = $this->service->errorResponse('Something went wrong');

    expect($response)->toBe([
        'response_type' => 'ephemeral',
        'text' => 'Something went wrong',
    ]);
});

test('taskCreatedResponse returns in_channel response with blocks', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $task = Task::factory()->create([
        'id' => 123,
        'title' => 'Fix the bug',
        'priority' => 'high',
        'project_id' => $project->id,
    ]);

    $response = $this->service->taskCreatedResponse($task, $project);

    expect($response['response_type'])->toBe('in_channel')
        ->and($response['blocks'])->toHaveCount(3)
        ->and($response['blocks'][0]['text']['text'])->toContain('Task created')
        ->and($response['blocks'][0]['text']['text'])->toContain('Test Project')
        ->and($response['blocks'][1]['text']['text'])->toContain('Fix the bug')
        ->and($response['blocks'][2]['elements'][0]['text'])->toContain('Task ID: #123')
        ->and($response['blocks'][2]['elements'][0]['text'])->toContain('Priority: high');
});

test('noteLoggedResponse returns in_channel response with blocks', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    $user = User::factory()->create();
    $note = ClientNote::factory()->create([
        'id' => 456,
        'content' => 'Client approved the design',
        'client_id' => $client->id,
        'user_id' => $user->id,
    ]);

    $response = $this->service->noteLoggedResponse($note, $client);

    expect($response['response_type'])->toBe('in_channel')
        ->and($response['blocks'])->toHaveCount(3)
        ->and($response['blocks'][0]['text']['text'])->toContain('Note logged')
        ->and($response['blocks'][0]['text']['text'])->toContain('Acme Corp')
        ->and($response['blocks'][1]['text']['text'])->toContain('Client approved the design')
        ->and($response['blocks'][2]['elements'][0]['text'])->toContain('Note ID: #456');
});

test('taskListBlocks includes lifecycle actions for actionable tasks', function () {
    $blocks = $this->service->taskListBlocks([
        'tasks' => [
            [
                'id' => 42,
                'title' => 'Fix auth redirect',
                'status' => 'pending',
                'priority' => 'high',
                'assignee' => null,
                'has_active_agent_task' => false,
            ],
        ],
    ], 'Client Tasks');

    $json = json_encode($blocks);

    expect($json)->toContain('task_mark_in_progress')
        ->and($json)->toContain('task_run_agent')
        ->and($json)->toContain('Run Dev Agent');
});

test('taskDetailBlocks summarizes the task and exposes controls', function () {
    $project = Project::factory()->create(['name' => 'Platform']);
    $task = Task::factory()->pending()->create([
        'id' => 77,
        'project_id' => $project->id,
        'title' => 'Harden webhook retries',
        'priority' => 'urgent',
    ]);

    $blocks = $this->service->taskDetailBlocks($task, 'Updated from Slack');
    $json = json_encode($blocks);

    expect($json)->toContain('Task #77')
        ->and($json)->toContain('Harden webhook retries')
        ->and($json)->toContain('Updated from Slack')
        ->and($json)->toContain('Run Dev Agent');
});

test('threadSummaryBlocks renders task run approval and billing context', function () {
    $blocks = $this->service->threadSummaryBlocks([
        'thread' => [
            'context_id' => 99,
            'current_state' => 'idle',
            'last_interaction_at' => now()->toIso8601String(),
            'pending_actions_count' => 1,
            'history_count' => 4,
            'pending_action_types' => ['trigger_agent'],
        ],
        'channel_context' => [
            'client' => ['name' => 'Acme'],
            'project' => ['name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ],
        'staging' => [
            'deployment' => [
                'workflow_identifier' => 'deploy.yml',
                'publish_branch' => 'main',
                'staging_url' => 'https://staging.acme.test',
            ],
            'summary' => [
                'required' => 1,
                'configured' => 1,
            ],
            'required_secrets' => [],
            'ready_to_publish' => true,
            'workflow_run' => [
                'workflow_run_id' => 991,
                'workflow_status' => 'completed',
                'deployment_status' => 'deployed',
                'workflow_url' => 'https://github.com/acme/platform/actions/runs/991',
                'staging_url' => 'https://staging.acme.test',
            ],
        ],
        'task' => [
            'id' => 42,
            'title' => 'Fix checkout flow',
            'status' => 'review',
            'priority' => 'high',
            'client_name' => 'Acme',
            'project_name' => 'Platform Refresh',
        ],
        'run' => [
            'id' => 77,
            'agent_id' => 5,
            'agent_name' => 'Dev Agent',
            'status' => 'completed',
            'issue_number' => 42,
            'repo' => 'acme/platform',
            'pr_number' => 17,
            'pr_url' => 'https://github.com/acme/platform/pull/17',
            'branch' => 'fix/checkout-flow',
            'review_url' => 'https://staging.acme.test',
            'deployment_status' => 'deployed',
            'workflow_run_id' => 8123,
        ],
        'approvals' => [
            [
                'id' => 12,
                'description' => 'Approve staging deploy',
                'risk_level' => 'high',
                'action_type' => 'deploy_code',
            ],
        ],
        'pending_interaction' => [
            'id' => 55,
            'question_type' => 'confirm',
            'question_content' => 'Ship this fix to staging?',
        ],
        'invoice' => [
            'number' => 'INV-2042',
            'client_name' => 'Acme',
            'status' => 'draft',
            'total' => 2500,
            'amount_due' => 2500,
            'due_date' => now()->addWeek()->toDateString(),
        ],
        'website_project' => [
            'id' => 9,
            'name' => 'Acme Relaunch',
            'status' => 'building',
            'project_type' => 'autonomous',
        ],
    ]);

    $json = json_encode($blocks);

    expect($json)->toContain('Thread Status')
        ->and($json)->toContain('Staging Workflow')
        ->and($json)->toContain('deploy.yml')
        ->and($json)->toContain('Open Workflow')
        ->and($json)->toContain('Fix checkout flow')
        ->and($json)->toContain('PR #17')
        ->and($json)->toContain('Approve staging deploy')
        ->and($json)->toContain('thread_request_review_deploy')
        ->and($json)->toContain('approval_approve')
        ->and($json)->toContain('interaction_respond_yes')
        ->and($json)->toContain('thread_retry_run')
        ->and($json)->toContain('INV-2042')
        ->and($json)->toContain('Acme Relaunch');
});

test('agentRunListBlocks render recent project runs with delivery context', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'running',
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'task' => 'Work GitHub issue #42 in owner/repo',
        'context' => [
            'engineering' => [
                'issue_number' => 42,
                'delivery_target' => 'staging',
                'repo' => 'owner/repo',
            ],
        ],
        'output' => [
            'branch' => 'fix/login-flow',
        ],
    ]);

    $blocks = $this->service->agentRunListBlocks([$run], 'Platform Refresh', 'active');
    $json = json_encode($blocks);

    expect($json)->toContain('Active Runs')
        ->and($json)->toContain('Platform Refresh')
        ->and($json)->toContain('Run #'.$run->id)
        ->and($json)->toContain('owner\\/repo')
        ->and($json)->toContain('Staging');
});

test('agentRunDetailBlocks render run links and delivery metadata', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'task' => 'Fix checkout regression',
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
            'workflow_url' => 'https://github.com/owner/repo/actions/runs/11',
            'branch' => 'fix/checkout-regression',
        ],
    ]);

    $blocks = $this->service->agentRunDetailBlocks($run);
    $json = json_encode($blocks);

    expect($json)->toContain('Agent Run')
        ->and($json)->toContain('Run #'.$run->id)
        ->and($json)->toContain('Open PR')
        ->and($json)->toContain('Open Workflow')
        ->and($json)->toContain('fix\\/checkout-regression');
});

test('agentRunDetailBlocks expose cancel control for active runs', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_RUNNING,
        'task' => 'Investigate checkout regression',
        'context' => [
            'engineering' => [
                'repo' => 'owner/repo',
            ],
        ],
    ]);

    $blocks = $this->service->agentRunDetailBlocks($run);
    $json = json_encode($blocks);

    expect($json)->toContain('Cancel Run')
        ->and($json)->toContain('cancel_agent_run');
});

test('threadSummaryBlocks expose cancel control for active runs', function () {
    $blocks = $this->service->threadSummaryBlocks([
        'thread' => [
            'context_id' => 99,
            'current_state' => 'processing',
        ],
        'channel_context' => [
            'project' => ['name' => 'Platform Refresh', 'status' => 'active', 'github_repo' => 'acme/platform'],
        ],
        'run' => [
            'id' => 77,
            'agent_id' => 5,
            'agent_name' => 'Dev Agent',
            'status' => 'running',
            'repo' => 'acme/platform',
            'branch' => 'fix/checkout-flow',
            'pr_number' => 17,
        ],
        'approvals' => collect(),
    ]);

    $json = json_encode($blocks);

    expect($json)->toContain('Cancel Run')
        ->and($json)->toContain('cancel_agent_run');
});

test('agentListResponse returns ephemeral response with agent list', function () {
    $agents = collect([
        Agent::factory()->create(['name' => 'Dev Agent', 'slug' => 'dev-agent']),
        Agent::factory()->create(['name' => 'Marketing Agent', 'slug' => 'marketing-agent']),
    ]);

    $response = $this->service->agentListResponse($agents);

    expect($response['response_type'])->toBe('ephemeral')
        ->and($response['blocks'])->toHaveCount(4)
        ->and($response['blocks'][0]['text']['text'])->toBe('Available Agents')
        ->and($response['blocks'][1]['text']['text'])->toContain('dev-agent')
        ->and($response['blocks'][1]['text']['text'])->toContain('marketing-agent')
        ->and($response['blocks'][3]['elements'][0]['text'])->toContain('Usage:');
});

test('agentTriggeredResponse returns in_channel response with run info', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'id' => 789,
        'agent_id' => $agent->id,
        'status' => 'running',
    ]);

    $response = $this->service->agentTriggeredResponse($agent, $run, 'Fix the authentication bug');

    expect($response['response_type'])->toBe('in_channel')
        ->and($response['blocks'])->toHaveCount(2)
        ->and($response['blocks'][0]['text']['text'])->toContain('Agent triggered:')
        ->and($response['blocks'][0]['text']['text'])->toContain('Dev Agent')
        ->and($response['blocks'][0]['text']['text'])->toContain('Fix the authentication bug')
        ->and($response['blocks'][1]['elements'][0]['text'])->toContain('Run ID: #789')
        ->and($response['blocks'][1]['elements'][0]['text'])->toContain('Status: running');
});

test('agentTriggeredResponse works without task description', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'running',
    ]);

    $response = $this->service->agentTriggeredResponse($agent, $run);

    expect($response['blocks'][0]['text']['text'])->not->toContain("\n\n_");
});

test('statusResponse builds comprehensive status with healthy state', function () {
    $metrics = [
        'health' => 'healthy',
        'healthText' => 'All systems operational',
        'workspaceName' => 'Test Workspace',
        'activeAgents' => 5,
        'runningAgents' => 2,
        'recentFailures' => 0,
        'failedJobs' => 0,
        'activeProjects' => 10,
        'pendingTasks' => 25,
        'inProgressTasks' => 8,
    ];

    $response = $this->service->statusResponse($metrics);

    expect($response['response_type'])->toBe('ephemeral')
        ->and($response['blocks'][0]['text']['text'])->toContain(':large_green_circle:')
        ->and($response['blocks'][0]['text']['text'])->toContain('System Status');

    $responseJson = json_encode($response['blocks']);
    expect($responseJson)->toContain('Test Workspace')
        ->and($responseJson)->toContain('Agents')
        ->and($responseJson)->toContain('Projects & Tasks');
});

test('statusResponse includes channel info when provided', function () {
    $client = Client::factory()->create(['name' => 'Client X']);
    $channel = SlackChannel::factory()->create([
        'monitoring_enabled' => true,
        'client_id' => $client->id,
    ]);

    $metrics = [
        'health' => 'healthy',
        'healthText' => 'OK',
        'workspaceName' => 'Test',
        'activeAgents' => 1,
        'runningAgents' => 0,
        'recentFailures' => 0,
        'failedJobs' => 0,
        'activeProjects' => 1,
        'pendingTasks' => 0,
        'inProgressTasks' => 0,
    ];

    $response = $this->service->statusResponse($metrics, $channel);

    $responseJson = json_encode($response['blocks']);
    expect($responseJson)->toContain('This Channel')
        ->and($responseJson)->toContain('Enabled')
        ->and($responseJson)->toContain('Client X');
});

test('statusResponse shows warning emoji for warning health', function () {
    $metrics = [
        'health' => 'warning',
        'healthText' => 'Some issues',
        'workspaceName' => 'Test',
        'activeAgents' => 1,
        'runningAgents' => 0,
        'recentFailures' => 2,
        'failedJobs' => 1,
        'activeProjects' => 1,
        'pendingTasks' => 0,
        'inProgressTasks' => 0,
    ];

    $response = $this->service->statusResponse($metrics);

    expect($response['blocks'][0]['text']['text'])->toContain(':large_yellow_circle:');
});

test('statusResponse shows degraded emoji for degraded health', function () {
    $metrics = [
        'health' => 'degraded',
        'healthText' => 'System degraded',
        'workspaceName' => 'Test',
        'activeAgents' => 1,
        'runningAgents' => 0,
        'recentFailures' => 5,
        'failedJobs' => 10,
        'activeProjects' => 1,
        'pendingTasks' => 0,
        'inProgressTasks' => 0,
    ];

    $response = $this->service->statusResponse($metrics);

    expect($response['blocks'][0]['text']['text'])->toContain(':red_circle:');
});

test('confirmationResponse builds dialog with buttons', function () {
    $response = $this->service->confirmationResponse(
        'Confirm Action',
        'Are you sure you want to proceed?',
        [
            'text' => 'Yes, do it',
            'style' => 'danger',
            'action_id' => 'confirm_delete',
            'value' => 'item_123',
        ],
        'No, cancel'
    );

    expect($response['response_type'])->toBe('ephemeral')
        ->and($response['blocks'])->toHaveCount(3)
        ->and($response['blocks'][0]['text']['text'])->toBe('Confirm Action')
        ->and($response['blocks'][1]['text']['text'])->toBe('Are you sure you want to proceed?')
        ->and($response['blocks'][2]['type'])->toBe('actions')
        ->and($response['blocks'][2]['elements'])->toHaveCount(2)
        ->and($response['blocks'][2]['elements'][0]['style'])->toBe('danger')
        ->and($response['blocks'][2]['elements'][0]['action_id'])->toBe('confirm_delete')
        ->and($response['blocks'][2]['elements'][1]['text']['text'])->toBe('No, cancel');
});

test('agentRunStatusUpdate builds status message for running', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'running',
    ]);

    $response = $this->service->agentRunStatusUpdate($run);

    expect($response['blocks'][0]['text']['text'])->toContain(':hourglass_flowing_sand:')
        ->and($response['blocks'][0]['text']['text'])->toContain('Agent Run Update');
});

test('agentRunStatusUpdate builds status message for completed with output', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'completed',
        'output' => ['summary' => 'Task completed successfully'],
    ]);

    $response = $this->service->agentRunStatusUpdate($run);

    expect($response['blocks'][0]['text']['text'])->toContain(':white_check_mark:');

    $responseJson = json_encode($response['blocks']);
    expect($responseJson)->toContain('Task completed successfully');
});

test('agentRunStatusUpdate builds status message for failed with error', function () {
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'failed',
        'error_message' => 'Connection timeout',
    ]);

    $response = $this->service->agentRunStatusUpdate($run);

    expect($response['blocks'][0]['text']['text'])->toContain(':x:');

    $responseJson = json_encode($response['blocks']);
    expect($responseJson)->toContain('Connection timeout');
});

test('taskCreationModal builds modal with project options', function () {
    $projects = [
        Project::factory()->create(['name' => 'Project Alpha'])->toArray(),
        Project::factory()->create(['name' => 'Project Beta'])->toArray(),
    ];

    $modal = $this->service->taskCreationModal($projects, 'Prefilled title', 'Prefilled description');

    expect($modal['type'])->toBe('modal')
        ->and($modal['callback_id'])->toBe('create_task_modal')
        ->and($modal['title']['text'])->toBe('Create Task')
        ->and($modal['blocks'])->toHaveCount(5);

    $titleBlock = $modal['blocks'][0];
    expect($titleBlock['element']['initial_value'])->toBe('Prefilled title');

    $descriptionBlock = $modal['blocks'][1];
    expect($descriptionBlock['element']['initial_value'])->toBe('Prefilled description');

    $projectBlock = $modal['blocks'][2];
    expect($projectBlock['element']['options'])->toHaveCount(2)
        ->and($projectBlock['element']['options'][0]['text']['text'])->toBe('Project Alpha')
        ->and($projectBlock['element']['options'][1]['value'])->toBe('2');
});

test('stagingSecretModal builds a private secret capture modal', function () {
    $modal = $this->service->stagingSecretModal([
        'project' => ['name' => 'Platform Refresh'],
        'repo' => ['full_name' => 'acme/platform'],
    ], 'VERCEL_TOKEN');

    expect($modal['type'])->toBe('modal')
        ->and($modal['callback_id'])->toBe('staging_secret_modal')
        ->and($modal['title']['text'])->toBe('Add Staging Secret')
        ->and($modal['blocks'][1]['element']['initial_value'])->toBe('VERCEL_TOKEN')
        ->and($modal['blocks'][2]['element']['initial_option']['value'])->toBe('environment');
});

test('stagingStatusBlocks render secret readiness and publish actions', function () {
    $blocks = $this->service->stagingStatusBlocks([
        'client' => ['name' => 'Acme'],
        'project' => ['name' => 'Platform Refresh'],
        'repo' => ['full_name' => 'acme/platform', 'default_branch' => 'main'],
        'deployment' => [
            'deployment_type' => 'vercel',
            'workflow_identifier' => 'deploy.yml',
            'publish_branch' => 'main',
            'staging_url' => 'https://staging.acme.test',
        ],
        'summary' => [
            'required' => 2,
            'configured' => 1,
            'synced' => 1,
        ],
        'required_secrets' => [
            [
                'name' => 'VERCEL_TOKEN',
                'sync_status' => 'missing',
                'target_scope' => 'environment',
            ],
            [
                'name' => 'VERCEL_ORG_ID',
                'sync_status' => 'synced',
                'target_scope' => 'repository',
            ],
        ],
        'ready_to_publish' => true,
    ]);

    $json = json_encode($blocks);

    expect($json)->toContain('Staging Workflow')
        ->and($json)->toContain('VERCEL_TOKEN')
        ->and($json)->toContain('Add Secret')
        ->and($json)->toContain('Sync Secrets')
        ->and($json)->toContain('Publish to Staging');
});

test('messageWithActions builds message with buttons', function () {
    $response = $this->service->messageWithActions('Choose an option:', [
        ['text' => 'Option A', 'action_id' => 'select_a', 'value' => 'a', 'style' => 'primary'],
        ['text' => 'Option B', 'action_id' => 'select_b', 'value' => 'b'],
    ]);

    expect($response['blocks'])->toHaveCount(2)
        ->and($response['blocks'][0]['text']['text'])->toBe('Choose an option:')
        ->and($response['blocks'][1]['type'])->toBe('actions')
        ->and($response['blocks'][1]['elements'])->toHaveCount(2)
        ->and($response['blocks'][1]['elements'][0]['style'])->toBe('primary')
        ->and($response['blocks'][1]['elements'][1])->not->toHaveKey('style');
});

test('actionItemDetectedResponse builds high confidence notification', function () {
    $response = $this->service->actionItemDetectedResponse(
        'Need to update the documentation',
        0.85,
        'Create a task to update docs'
    );

    expect($response['response_type'])->toBe('ephemeral')
        ->and($response['blocks'][0]['text']['text'])->toContain(':high_brightness:')
        ->and($response['blocks'][0]['text']['text'])->toContain('Action Item Detected')
        ->and($response['blocks'][1]['text']['text'])->toContain('Need to update the documentation')
        ->and($response['blocks'][2]['elements'][0]['text'])->toContain('85%');

    $actionsBlock = collect($response['blocks'])->firstWhere('type', 'actions');
    expect($actionsBlock['elements'])->toHaveCount(2)
        ->and($actionsBlock['elements'][0]['action_id'])->toBe('create_task_from_action_item');
});

test('actionItemDetectedResponse builds medium confidence notification', function () {
    $response = $this->service->actionItemDetectedResponse(
        'Maybe we should consider this',
        0.55
    );

    expect($response['blocks'][0]['text']['text'])->toContain(':low_brightness:')
        ->and($response['blocks'][2]['elements'][0]['text'])->toContain('55%');

    $actionsBlock = collect($response['blocks'])->firstWhere('type', 'actions');
    expect($actionsBlock)->toBeNull();
});

test('header creates proper header block', function () {
    $block = $this->service->header('Test Header');

    expect($block)->toBe([
        'type' => 'header',
        'text' => [
            'type' => 'plain_text',
            'text' => 'Test Header',
        ],
    ]);
});

test('section creates proper section block with mrkdwn', function () {
    $block = $this->service->section('*Bold* and _italic_');

    expect($block)->toBe([
        'type' => 'section',
        'text' => [
            'type' => 'mrkdwn',
            'text' => '*Bold* and _italic_',
        ],
    ]);
});

test('context creates proper context block', function () {
    $block = $this->service->context(['Element 1', 'Element 2']);

    expect($block['type'])->toBe('context')
        ->and($block['elements'])->toHaveCount(2)
        ->and($block['elements'][0])->toBe(['type' => 'mrkdwn', 'text' => 'Element 1'])
        ->and($block['elements'][1])->toBe(['type' => 'mrkdwn', 'text' => 'Element 2']);
});

test('divider creates proper divider block', function () {
    $block = $this->service->divider();

    expect($block)->toBe(['type' => 'divider']);
});

test('field creates proper field with label and value', function () {
    $block = $this->service->field('Status', 'Active');

    expect($block)->toBe([
        'type' => 'mrkdwn',
        'text' => "*Status:*\nActive",
    ]);
});
