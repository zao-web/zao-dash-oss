<?php

use App\Jobs\SyncAgentsJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\DeploymentConfig;
use App\Models\GitHubInstallation;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\SlackChannel;
use App\Models\SlackThreadContext;
use App\Models\SlackWorkspace;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use App\Services\Slack\SlackApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.github.webhook_secret' => 'test-secret']);
});

test('rejects webhook with invalid signature', function () {
    $payload = ['action' => 'opened'];

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issues',
        'X-Hub-Signature-256' => 'invalid-signature',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Invalid signature']);
});

test('accepts webhook with valid signature', function () {
    $payload = json_encode(['action' => 'unknown']);
    $secret = config('services.github.webhook_secret');
    $signature = 'sha256='.hash_hmac('sha256', $payload, $secret);

    $response = $this->postJson('/webhooks/github', json_decode($payload, true), [
        'X-GitHub-Event' => 'ping',
        'X-Hub-Signature-256' => $signature,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('accepts webhook without signature when secret not configured', function () {
    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', ['action' => 'test'], [
        'X-GitHub-Event' => 'ping',
    ]);

    $response->assertStatus(200);
});

test('handles installation created event', function () {
    $this->mock(GitHubAppService::class)
        ->shouldReceive('handleInstallationWebhook')
        ->once()
        ->with('created', \Mockery::type('array'));

    $payload = [
        'action' => 'created',
        'installation' => [
            'id' => 12345,
            'account' => [
                'login' => 'testorg',
                'type' => 'Organization',
            ],
        ],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'installation',
    ]);

    $response->assertStatus(200);
});

test('handles installation_repositories event', function () {
    $installation = GitHubInstallation::factory()->create([
        'installation_id' => 12345,
    ]);

    $this->mock(GitHubAppService::class)
        ->shouldReceive('syncRepos')
        ->once()
        ->with(\Mockery::on(fn ($inst) => $inst->id === $installation->id));

    $payload = [
        'action' => 'added',
        'installation' => ['id' => 12345],
        'repositories_added' => [
            ['id' => 1, 'name' => 'test-repo'],
        ],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'installation_repositories',
    ]);

    $response->assertStatus(200);
});

test('handles issue opened event for monitored repo', function () {
    Queue::fake();

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storeIssue')
        ->once()
        ->andReturn(GitHubIssue::factory()->create([
            'repo_id' => $repo->id,
            'issue_number' => 42,
            'labels' => [],
        ]))
        ->shouldReceive('syncIssueToTask')
        ->once();

    $payload = [
        'action' => 'opened',
        'issue' => [
            'id' => 1,
            'number' => 42,
            'title' => 'Test Issue',
            'body' => 'Test description',
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200);
});

test('triggers DevAgent for issue with agent label', function () {
    Queue::fake();
    Bus::fake();

    $agent = Agent::factory()->create([
        'slug' => 'dev-agent',
        'status' => 'active',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'full_name' => 'owner/repo',
        'monitoring_enabled' => true,
    ]);

    $issue = GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'issue_number' => 42,
        'title' => 'Implement feature',
        'labels' => ['agent'],
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storeIssue')
        ->once()
        ->andReturn($issue)
        ->shouldReceive('syncIssueToTask')
        ->once();

    $payload = [
        'action' => 'opened',
        'issue' => [
            'id' => 1,
            'number' => 42,
            'title' => 'Implement feature',
            'labels' => [['name' => 'agent']],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200);
});

test('ignores issue events for non-monitored repos', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => false,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldNotReceive('storeIssue');

    $payload = [
        'action' => 'opened',
        'issue' => ['id' => 1, 'number' => 42],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200);
});

test('handles issue closed event', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $task = \App\Models\Task::factory()->create();

    $issue = GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'task_id' => $task->id,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storeIssue')
        ->once()
        ->andReturn($issue);

    $payload = [
        'action' => 'closed',
        'issue' => ['id' => 1, 'number' => 42],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issues',
    ]);

    $response->assertStatus(200);
});

test('handles pull request opened targeting main', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'base_branch' => 'main',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storePullRequest')
        ->once()
        ->andReturn($pr);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'id' => 1,
            'number' => 10,
            'base' => ['ref' => 'main'],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
    ]);

    $response->assertStatus(200);
    expect(ApprovalRequest::count())->toBeGreaterThan(0);
});

test('handles pull request opened targeting develop', function () {
    Queue::fake();
    Bus::fake();

    $agent = Agent::factory()->create([
        'slug' => 'qa-agent',
        'status' => 'active',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'base_branch' => 'develop',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storePullRequest')
        ->once()
        ->andReturn($pr);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'id' => 1,
            'number' => 10,
            'base' => ['ref' => 'develop'],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
    ]);

    $response->assertStatus(200);
});

test('syncs slack engineering run with pull request metadata on pr open', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'full_name' => 'owner/repo',
    ]);

    $run = AgentRun::factory()->completed()->create([
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'summary' => 'PR is coming next',
        ],
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'title' => 'Fix the bug',
        'body' => 'Fixes #42',
        'head_branch' => 'feature/fix-42',
        'base_branch' => 'develop',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storePullRequest')
        ->once()
        ->andReturn($pr);

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', [
        'action' => 'opened',
        'pull_request' => [
            'id' => 1,
            'number' => 10,
            'title' => 'Fix the bug',
            'body' => 'Fixes #42',
            'base' => ['ref' => 'develop'],
            'head' => ['ref' => 'feature/fix-42'],
        ],
        'repository' => ['id' => 98765],
    ], [
        'X-GitHub-Event' => 'pull_request',
    ]);

    $response->assertStatus(200);

    $run->refresh();
    expect($run->output['pr_number'])->toBe(10)
        ->and($run->output['branch'])->toBe('feature/fix-42')
        ->and($run->output['pr_url'])->toBe($pr->url);
});

test('handles pull request merged to main', function () {
    Queue::fake();
    Bus::fake();

    $agent = Agent::factory()->create([
        'slug' => 'deploy-agent',
        'status' => 'active',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'deployment_config' => ['env' => 'production'],
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'base_branch' => 'main',
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('storePullRequest')
        ->once()
        ->andReturn($pr);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'id' => 1,
            'number' => 10,
            'merged' => true,
            'merged_at' => '2024-01-01T00:00:00Z',
            'base' => ['ref' => 'main'],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
    ]);

    $response->assertStatus(200);
});

test('handles pull request review approved for develop', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'base_branch' => 'develop',
        'checks_passed' => true,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldReceive('mergePullRequest')
        ->once()
        ->with(\Mockery::on(fn ($repoModel) => $repoModel->id === $repo->id), 10);

    $payload = [
        'action' => 'submitted',
        'review' => ['state' => 'approved'],
        'pull_request' => [
            'number' => 10,
            'base' => ['ref' => 'develop'],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request_review',
    ]);

    $response->assertStatus(200);
});

test('does not auto-merge PR when checks have not passed', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'base_branch' => 'develop',
        'checks_passed' => false,
    ]);

    $this->mock(GitHubApiService::class)
        ->shouldNotReceive('mergePullRequest');

    $payload = [
        'action' => 'submitted',
        'review' => ['state' => 'approved'],
        'pull_request' => [
            'number' => 10,
            'base' => ['ref' => 'develop'],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request_review',
    ]);

    $response->assertStatus(200);
});

test('handles push to main branch', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'full_name' => 'owner/repo',
    ]);

    $payload = [
        'ref' => 'refs/heads/main',
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(200);
});

test('handles push to develop branch', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'full_name' => 'owner/repo',
    ]);

    $payload = [
        'ref' => 'refs/heads/develop',
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(200);
});

test('handles check suite completion', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'checks_passed' => false,
    ]);

    $payload = [
        'check_suite' => [
            'conclusion' => 'success',
            'pull_requests' => [
                ['number' => 10],
            ],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'check_suite',
    ]);

    $response->assertStatus(200);

    $pr->refresh();
    expect($pr->checks_passed)->toBeTrue();
});

test('workflow run posts deployed review update back to originating slack thread', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'full_name' => 'owner/repo',
    ]);

    DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'production_url' => null,
        'build_command' => null,
        'build_output_dir' => null,
        'secrets' => null,
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
        'onboarding_agent_run_id' => null,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'head_branch' => 'feature/fix-42',
        'base_branch' => 'develop',
    ]);

    $run = AgentRun::factory()->completed()->create([
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'pr_url' => $pr->url,
            'pr_number' => 10,
            'branch' => 'feature/fix-42',
        ],
    ]);

    $slackApi = \Mockery::mock(SlackApiService::class);
    $slackApi->shouldReceive('postMessage')
        ->once()
        ->with(
            \Mockery::on(fn ($workspaceArg) => $workspaceArg->id === $workspace->id),
            $channel->channel_id,
            '',
            \Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? [], JSON_UNESCAPED_SLASHES);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'GitHub Actions deployed this branch')
                    && str_contains($blocksJson, 'Open Review Build')
                    && str_contains($blocksJson, 'staging.owner-repo.test');
            })
        )
        ->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $slackApi);

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', [
        'action' => 'completed',
        'workflow_run' => [
            'id' => 123,
            'name' => 'Deploy Preview',
            'html_url' => 'https://github.com/owner/repo/actions/runs/123',
            'conclusion' => 'success',
            'head_branch' => 'feature/fix-42',
            'pull_requests' => [
                ['number' => 10],
            ],
        ],
        'repository' => ['id' => 98765],
    ], [
        'X-GitHub-Event' => 'workflow_run',
    ]);

    $response->assertStatus(200);

    $run->refresh();
    expect($run->output['deployment_status'])->toBe('deployed')
        ->and($run->output['staging_url'])->toBe('https://staging.owner-repo.test')
        ->and($run->output['workflow_url'])->toBe('https://github.com/owner/repo/actions/runs/123')
        ->and($run->output['workflow_run_id'])->toBe(123)
        ->and($run->output['workflow_status'])->toBe('completed');
});

test('workflow run requested posts queued review update back to originating slack thread', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'full_name' => 'owner/repo',
    ]);

    DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'production_url' => null,
        'build_command' => null,
        'build_output_dir' => null,
        'secrets' => null,
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
        'onboarding_agent_run_id' => null,
    ]);

    GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'head_branch' => 'feature/fix-42',
        'base_branch' => 'develop',
    ]);

    $run = AgentRun::factory()->completed()->create([
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'pr_url' => 'https://github.com/owner/repo/pull/10',
            'pr_number' => 10,
            'branch' => 'feature/fix-42',
        ],
    ]);

    $slackApi = \Mockery::mock(SlackApiService::class);
    $slackApi->shouldReceive('postMessage')
        ->once()
        ->with(
            \Mockery::on(fn ($workspaceArg) => $workspaceArg->id === $workspace->id),
            $channel->channel_id,
            '',
            \Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? [], JSON_UNESCAPED_SLASHES);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'queued a review deploy')
                    && str_contains($blocksJson, 'Open Workflow');
            })
        )
        ->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $slackApi);

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', [
        'action' => 'requested',
        'workflow_run' => [
            'id' => 321,
            'name' => 'Deploy Preview',
            'status' => 'queued',
            'html_url' => 'https://github.com/owner/repo/actions/runs/321',
            'head_branch' => 'feature/fix-42',
            'pull_requests' => [
                ['number' => 10],
            ],
        ],
        'repository' => ['id' => 98765],
    ], [
        'X-GitHub-Event' => 'workflow_run',
    ]);

    $response->assertStatus(200);

    expect($run->fresh()->output['deployment_status'])->toBe('queued')
        ->and($run->fresh()->output['workflow_run_id'])->toBe(321)
        ->and($run->fresh()->output['workflow_status'])->toBe('requested')
        ->and($run->fresh()->output['workflow_url'])->toBe('https://github.com/owner/repo/actions/runs/321');
});

test('workflow run failure posts failure details back to originating slack thread', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'full_name' => 'owner/repo',
    ]);

    DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'production_url' => null,
        'build_command' => null,
        'build_output_dir' => null,
        'secrets' => null,
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
        'onboarding_agent_run_id' => null,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'head_branch' => 'feature/fix-42',
        'base_branch' => 'develop',
    ]);

    $run = AgentRun::factory()->completed()->create([
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'workspace_id' => $workspace->workspace_id,
                'channel_id' => $channel->channel_id,
                'thread_ts' => '1234567890.123456',
            ],
            'engineering' => [
                'repo' => 'owner/repo',
                'issue_number' => 42,
            ],
        ],
        'output' => [
            'pr_url' => $pr->url,
            'pr_number' => 10,
            'branch' => 'feature/fix-42',
        ],
    ]);

    $slackApi = \Mockery::mock(SlackApiService::class);
    $slackApi->shouldReceive('postMessage')
        ->once()
        ->with(
            \Mockery::on(fn ($workspaceArg) => $workspaceArg->id === $workspace->id),
            $channel->channel_id,
            '',
            \Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? [], JSON_UNESCAPED_SLASHES);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'GitHub Actions reported `failure`')
                    && str_contains($blocksJson, 'Failure details: Preview deploy failed on smoke tests')
                    && str_contains($blocksJson, 'Open Workflow');
            })
        )
        ->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $slackApi);

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', [
        'action' => 'completed',
        'workflow_run' => [
            'id' => 322,
            'name' => 'Deploy Preview',
            'display_title' => 'Preview deploy failed on smoke tests',
            'html_url' => 'https://github.com/owner/repo/actions/runs/322',
            'conclusion' => 'failure',
            'head_branch' => 'feature/fix-42',
            'pull_requests' => [
                ['number' => 10],
            ],
        ],
        'repository' => ['id' => 98765],
    ], [
        'X-GitHub-Event' => 'workflow_run',
    ]);

    $response->assertStatus(200);

    expect($run->fresh()->output['workflow_conclusion'])->toBe('failure')
        ->and($run->fresh()->output['workflow_failure_summary'])->toBe('Preview deploy failed on smoke tests')
        ->and($run->fresh()->output['deployment_status'])->toBe('failure');
});

test('workflow run completion posts staging publish update back to originating slack thread without a pull request', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'T12345',
    ]);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C12345',
    ]);

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'monitoring_enabled' => true,
        'full_name' => 'owner/repo',
    ]);

    DeploymentConfig::query()->create([
        'client_id' => $repo->client_id ?? \App\Models\Client::factory()->create()->id,
        'repo_id' => $repo->id,
        'deployment_type' => 'vercel',
        'hosting_provider' => 'vercel',
        'staging_url' => 'https://staging.owner-repo.test',
        'workflow_file_path' => '.github/workflows/deploy.yml',
        'onboarding_completed' => true,
    ]);

    $threadContext = SlackThreadContext::factory()->create([
        'channel_id' => $channel->id,
        'thread_ts' => '1234567890.123456',
        'context_data' => [
            'staging_workflow' => [
                'repo_id' => $repo->id,
                'repo' => 'owner/repo',
                'branch' => 'main',
                'workflow_identifier' => 'deploy.yml',
                'workflow_status' => 'requested',
                'deployment_status' => 'queued',
            ],
        ],
    ]);

    $slackApi = \Mockery::mock(SlackApiService::class);
    $slackApi->shouldReceive('postMessage')
        ->once()
        ->with(
            \Mockery::on(fn ($workspaceArg) => $workspaceArg->id === $workspace->id),
            $channel->channel_id,
            '',
            \Mockery::on(function ($options) {
                $blocksJson = json_encode($options['blocks'] ?? [], JSON_UNESCAPED_SLASHES);

                return $options['thread_ts'] === '1234567890.123456'
                    && str_contains($blocksJson, 'Staging publish completed')
                    && str_contains($blocksJson, 'Open Staging')
                    && str_contains($blocksJson, 'Open Workflow');
            })
        )
        ->andReturn(['ok' => true]);
    app()->instance(SlackApiService::class, $slackApi);

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', [
        'action' => 'completed',
        'workflow_run' => [
            'id' => 901,
            'name' => 'Deploy Staging',
            'html_url' => 'https://github.com/owner/repo/actions/runs/901',
            'conclusion' => 'success',
            'head_branch' => 'main',
            'pull_requests' => [],
        ],
        'repository' => ['id' => 98765],
    ], [
        'X-GitHub-Event' => 'workflow_run',
    ]);

    $response->assertStatus(200);

    $threadContext->refresh();

    expect($threadContext->context_data['staging_workflow']['workflow_run_id'])->toBe(901)
        ->and($threadContext->context_data['staging_workflow']['workflow_status'])->toBe('completed')
        ->and($threadContext->context_data['staging_workflow']['deployment_status'])->toBe('deployed')
        ->and($threadContext->context_data['staging_workflow']['workflow_url'])->toBe('https://github.com/owner/repo/actions/runs/901')
        ->and($threadContext->context_data['staging_workflow']['staging_url'])->toBe('https://staging.owner-repo.test');
});

test('handles check run completion', function () {
    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
    ]);

    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 10,
        'checks_passed' => true,
    ]);

    $payload = [
        'check_run' => [
            'check_suite' => [
                'conclusion' => 'failure',
                'pull_requests' => [
                    ['number' => 10],
                ],
            ],
        ],
        'repository' => ['id' => 98765],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'check_run',
    ]);

    $response->assertStatus(200);

    $pr->refresh();
    expect($pr->checks_passed)->toBeFalse();
});

test('ignores unhandled event types', function () {
    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', ['action' => 'test'], [
        'X-GitHub-Event' => 'unknown_event',
    ]);

    $response->assertStatus(200);
    $response->assertJson(['ok' => true]);
});

test('dispatches SyncAgentsJob when agent definition files are pushed to zao-dash repo', function () {
    Queue::fake();

    $payload = [
        'ref' => 'refs/heads/main',
        'repository' => [
            'id' => 12345,
            'full_name' => 'zao/zao-dash',
        ],
        'commits' => [
            [
                'id' => 'abc123',
                'added' => [],
                'modified' => ['app/Agents/Definitions/TestAgent.php'],
                'removed' => [],
            ],
        ],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(200);

    Queue::assertPushed(SyncAgentsJob::class, function ($job) {
        return in_array('app/Agents/Definitions/TestAgent.php', $job->changedFiles);
    });
});

test('does not dispatch SyncAgentsJob when non-agent files are pushed', function () {
    Queue::fake();

    $payload = [
        'ref' => 'refs/heads/main',
        'repository' => [
            'id' => 12345,
            'full_name' => 'zao/zao-dash',
        ],
        'commits' => [
            [
                'id' => 'abc123',
                'added' => ['app/Models/User.php'],
                'modified' => ['app/Http/Controllers/HomeController.php'],
                'removed' => [],
            ],
        ],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(200);

    Queue::assertNotPushed(SyncAgentsJob::class);
});

test('does not dispatch SyncAgentsJob for non-zao-dash repos', function () {
    Queue::fake();

    $repo = GitHubRepo::factory()->create([
        'repo_id' => 98765,
        'full_name' => 'other/repo',
    ]);

    $payload = [
        'ref' => 'refs/heads/main',
        'repository' => [
            'id' => 98765,
            'full_name' => 'other/repo',
        ],
        'commits' => [
            [
                'id' => 'abc123',
                'added' => ['app/Agents/Definitions/SomeAgent.php'],
                'modified' => [],
                'removed' => [],
            ],
        ],
    ];

    config(['services.github.webhook_secret' => null]);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'push',
    ]);

    $response->assertStatus(200);

    Queue::assertNotPushed(SyncAgentsJob::class);
});
