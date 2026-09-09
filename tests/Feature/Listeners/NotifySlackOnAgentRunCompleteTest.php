<?php

use App\Events\AgentRunStatusChanged;
use App\Listeners\NotifySlackOnAgentRunComplete;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackBotResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->apiService = Mockery::mock(SlackApiService::class);
    $this->responseService = new SlackBotResponseService;
});

test('sends completion notification for successful slack-invoked run', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Test Agent']);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'output' => ['summary' => 'Task completed successfully'],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::on(fn ($ws) => $ws->id === $workspace->id),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'white_check_mark')
                    && str_contains($blocksJson, 'Completed')
                    && str_contains($blocksJson, 'Task completed successfully');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('sends failure notification for failed slack-invoked run', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Failed Agent']);

    $run = AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'error_message' => 'Something went wrong during execution',
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, ':x:')
                    && str_contains($blocksJson, 'Failed')
                    && str_contains($blocksJson, 'Something went wrong');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('sends cancellation notification for cancelled slack-invoked run', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Cancelled Agent']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_CANCELLED,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'error_message' => 'Cancelled from Slack by U12345',
        'completed_at' => now(),
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Cancelled')
                    && str_contains($blocksJson, 'Cancelled from Slack by U12345');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('sends completion notification for task-invoked run with slack context', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);
    $task = Task::factory()->create([
        'title' => 'Fix checkout race condition',
    ]);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_TASK,
        'task_id' => $task->id,
        'context' => [
            'task_id' => $task->id,
            'task_title' => $task->title,
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
            ],
        ],
        'output' => ['summary' => 'Opened PR and prepared review notes'],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Task #')
                    && str_contains($blocksJson, 'Fix checkout race condition')
                    && str_contains($blocksJson, 'Opened PR and prepared review notes');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('does not send notification for non-slack invocation source', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => 'manual',
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('does not send notification for running status', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->pending()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
            ],
        ],
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'pending');

    $listener->handle($event);
});

test('sends execution-start notification when a pending approval run begins', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);

    $run = AgentRun::factory()->create([
        'agent_id' => $agent->id,
        'status' => AgentRun::STATUS_RUNNING,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);

    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'current_state' => 'idle',
        'conversation_history' => [],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'Approval granted. Starting execution.')
                    && str_contains($blocksJson, 'Agent Run Update');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, AgentRun::STATUS_PENDING_APPROVAL);

    $listener->handle($event);

    $threadContext->refresh();

    expect($threadContext->current_state)->toBe('processing')
        ->and($threadContext->conversation_history)->toHaveCount(1)
        ->and($threadContext->conversation_history[0]['content'])->toContain('Approval granted');
});

test('does not send notification when slack context is missing', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [],
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('does not send notification when channel_id is missing from context', function () {
    $workspace = SlackWorkspace::factory()->create();
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
            ],
        ],
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('does not send notification when workspace is not found', function () {
    $agent = Agent::factory()->create();
    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => 'nonexistent_workspace',
                'channel_id' => 'C12345',
            ],
        ],
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('updates thread context on completion', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Context Agent']);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);

    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'current_state' => 'processing',
        'conversation_history' => [],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);

    $threadContext->refresh();

    expect($threadContext->current_state)->toBe('idle')
        ->and($threadContext->conversation_history)->toHaveCount(1)
        ->and($threadContext->conversation_history[0]['role'])->toBe('system')
        ->and($threadContext->conversation_history[0]['content'])->toContain('completed successfully');
});

test('updates thread context on failure', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Failing Agent']);

    $run = AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);

    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'agent_run_id' => $run->id,
        'current_state' => 'processing',
        'conversation_history' => [],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);

    $threadContext->refresh();

    expect($threadContext->current_state)->toBe('idle')
        ->and($threadContext->conversation_history[0]['content'])->toContain('failed');
});

test('truncates long output in notification', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $longOutput = str_repeat('A', 1000);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'output' => ['summary' => $longOutput],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, '...');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('truncates long error message in notification', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $longError = str_repeat('E', 600);

    $run = AgentRun::factory()->failed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'error_message' => $longError,
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, '...')
                    && str_contains($blocksJson, 'Error');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('includes cost in context when available', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'cost_usd' => 0.0125,
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Cost:')
                    && str_contains($blocksJson, '$0.0125');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('includes view details link in context', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'output' => ['summary' => 'Test output for view details'],
    ]);

    $capturedBlocks = null;

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($ws, $channelId, $text, $options) use (&$capturedBlocks) {
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);

    expect($capturedBlocks)->not->toBeNull();

    // Use JSON_UNESCAPED_SLASHES to avoid escaping issues in assertions
    $blocksJson = json_encode($capturedBlocks, JSON_UNESCAPED_SLASHES);
    expect($blocksJson)->toContain('View Details');
    expect($blocksJson)->toContain('/agents/');
    expect($blocksJson)->toContain('/runs/');
});

test('handles api error gracefully', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andThrow(new \Exception('Slack API Error'));

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);

    expect(true)->toBeTrue();
});

test('listener is queued on slack-notifications queue', function () {
    $listener = new NotifySlackOnAgentRunComplete(
        Mockery::mock(SlackApiService::class),
        new SlackBotResponseService
    );

    expect($listener->queue)->toBe('slack-notifications');
});

test('handles json output format', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'output' => ['key1' => 'value1', 'key2' => 'value2'],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Output');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('handles result key in output array', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
        ],
        'output' => ['result' => 'Task executed with result key'],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            Mockery::any(),
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Task executed with result key');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('does not send notification for pending_approval status', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create();

    $run = AgentRun::factory()->pendingApproval()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
            ],
        ],
    ]);

    $this->apiService->shouldNotReceive('postMessage');

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});

test('surfaces PR ready context for engineering runs', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'owner/repo',
            ],
        ],
        'output' => [
            'summary' => 'Fixed the issue',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'branch' => 'fix/issue-42',
        ],
    ]);

    $capturedBlocks = null;

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->andReturnUsing(function ($workspaceArg, $channelId, $text, $options) use (&$capturedBlocks, $channel) {
            expect($channelId)->toBe($channel->channel_id);
            $capturedBlocks = $options['blocks'] ?? [];

            return ['ok' => true];
        });

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);

    $blocksJson = json_encode($capturedBlocks, JSON_UNESCAPED_SLASHES);

    expect($blocksJson)->toContain('PR Ready')
        ->and($blocksJson)->toContain('Open PR')
        ->and($blocksJson)->toContain('Issue #42')
        ->and($blocksJson)->toContain('fix/issue-42');
});

test('surfaces review build context for engineering runs', function () {
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $agent = Agent::factory()->create(['name' => 'Dev Agent']);

    $run = AgentRun::factory()->completed()->create([
        'agent_id' => $agent->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'issue_number' => 42,
                'repo' => 'owner/repo',
            ],
        ],
        'output' => [
            'summary' => 'Deployed the fix',
            'pr_url' => 'https://github.com/owner/repo/pull/42',
            'preview_url' => 'https://review.example.test',
            'deployment_status' => 'deployed',
        ],
    ]);

    $this->apiService->shouldReceive('postMessage')
        ->once()
        ->with(
            Mockery::any(),
            $channel->channel_id,
            '',
            Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? []);

                return str_contains($blocksJson, 'Review Ready')
                    && str_contains($blocksJson, 'Open Review Build')
                    && str_contains($blocksJson, 'review.example.test')
                    && str_contains($blocksJson, 'Deploy: deployed');
            })
        )
        ->andReturn(['ok' => true]);

    $listener = new NotifySlackOnAgentRunComplete($this->apiService, $this->responseService);

    $event = new AgentRunStatusChanged($run, 'running');

    $listener->handle($event);
});
