<?php

use App\Models\GitHubInstallation;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\User;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->appService = Mockery::mock(GitHubAppService::class);
    $this->apiService = Mockery::mock(GitHubApiService::class);

    $this->app->instance(GitHubAppService::class, $this->appService);
    $this->app->instance(GitHubApiService::class, $this->apiService);
});

// Status endpoint tests
test('status returns empty when no installations', function () {
    $response = $this->getJson('/api/integrations/github/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => false,
        'installations' => [],
    ]);
});

test('status returns installations with repos', function () {
    $installation = GitHubInstallation::factory()
        ->has(GitHubRepo::factory()->count(3)->state(['monitoring_enabled' => true]))
        ->create();

    $response = $this->getJson('/api/integrations/github/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
    ]);
    $response->assertJsonCount(1, 'installations');
    $response->assertJsonPath('installations.0.repos_count', 3);
});

test('status requires authentication', function () {
    auth()->logout();

    $response = $this->getJson('/api/integrations/github/status');

    $response->assertStatus(401);
});

// Install URL tests
test('install url returns github app installation url', function () {
    $expectedUrl = 'https://github.com/apps/test-app/installations/new';
    $this->appService->shouldReceive('getInstallationUrl')
        ->once()
        ->andReturn($expectedUrl);

    $response = $this->getJson('/api/integrations/github/install-url');

    $response->assertStatus(200);
    $response->assertJson(['url' => $expectedUrl]);
});

// Callback tests
test('callback handles successful installation', function () {
    $installationId = 12345;
    $installationData = [
        'id' => $installationId,
        'account' => [
            'login' => 'test-org',
            'type' => 'Organization',
        ],
    ];

    $this->appService->shouldReceive('listInstallations')
        ->once()
        ->andReturn([$installationData]);

    $installation = GitHubInstallation::factory()->make([
        'installation_id' => $installationId,
    ]);

    $this->appService->shouldReceive('storeInstallation')
        ->once()
        ->with(Mockery::on(fn ($arg) => $arg['id'] === $installationId))
        ->andReturn($installation);

    $this->appService->shouldReceive('syncRepos')
        ->once()
        ->with($installation);

    $response = $this->get("/integrations/github/callback?installation_id={$installationId}&setup_action=install");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
});

test('callback handles failed installation', function () {
    $installationId = 12345;

    $this->appService->shouldReceive('listInstallations')
        ->once()
        ->andThrow(new Exception('GitHub API error'));

    $response = $this->get("/integrations/github/callback?installation_id={$installationId}&setup_action=install");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
});

test('callback handles cancelled installation', function () {
    $response = $this->get('/integrations/github/callback?setup_action=cancel');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
});

// Sync installations tests
test('sync installations fetches and stores all installations', function () {
    $installationsData = [
        ['id' => 1, 'account' => ['login' => 'org1']],
        ['id' => 2, 'account' => ['login' => 'org2']],
    ];

    $this->appService->shouldReceive('listInstallations')
        ->once()
        ->andReturn($installationsData);

    $this->appService->shouldReceive('storeInstallation')
        ->twice()
        ->andReturn(GitHubInstallation::factory()->make());

    $this->appService->shouldReceive('syncRepos')
        ->twice();

    $response = $this->postJson('/api/integrations/github/sync-installations');

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'synced' => 2,
    ]);
});

test('sync installations handles api errors', function () {
    $this->appService->shouldReceive('listInstallations')
        ->once()
        ->andThrow(new Exception('API error'));

    $response = $this->postJson('/api/integrations/github/sync-installations');

    $response->assertStatus(500);
    $response->assertJsonPath('error', 'API error');
});

// List repos tests
test('list repos returns repos for installation', function () {
    $installation = GitHubInstallation::factory()
        ->has(GitHubRepo::factory()->count(3))
        ->create();

    $response = $this->getJson("/api/integrations/github/installations/{$installation->id}/repos");

    $response->assertStatus(200);
    $response->assertJsonCount(3, 'repos');
    $response->assertJsonStructure([
        'repos' => [
            '*' => [
                'id',
                'repo_id',
                'full_name',
                'is_private',
                'default_branch',
                'monitoring_enabled',
                'open_issues_count',
                'open_prs_count',
            ],
        ],
    ]);
});

// Update repo tests
test('update repo updates monitoring settings', function () {
    $repo = GitHubRepo::factory()->create(['monitoring_enabled' => false]);

    $response = $this->putJson("/api/integrations/github/repos/{$repo->id}", [
        'monitoring_enabled' => true,
    ]);

    $response->assertStatus(200);
    expect($repo->fresh()->monitoring_enabled)->toBeTrue();
});

test('update repo validates deployment type', function () {
    $repo = GitHubRepo::factory()->create();

    $response = $this->putJson("/api/integrations/github/repos/{$repo->id}", [
        'deployment_type' => 'invalid',
    ]);

    $response->assertStatus(422);
});

// Sync issues tests
test('sync issues fetches and stores issues', function () {
    $repo = GitHubRepo::factory()->create(['monitoring_enabled' => true]);

    $issuesData = [
        ['number' => 1, 'title' => 'Issue 1', 'state' => 'open'],
        ['number' => 2, 'title' => 'Issue 2', 'state' => 'open'],
    ];

    $this->apiService->shouldReceive('listIssues')
        ->once()
        ->with(Mockery::on(fn ($r) => $r->id === $repo->id))
        ->andReturn($issuesData);

    $this->apiService->shouldReceive('storeIssue')
        ->twice()
        ->andReturn(Mockery::mock());

    $this->apiService->shouldReceive('syncIssueToTask')
        ->twice();

    $response = $this->postJson("/api/integrations/github/repos/{$repo->id}/sync-issues");

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'synced' => 2,
    ]);
});

test('sync issues skips pull requests', function () {
    $repo = GitHubRepo::factory()->create();

    $issuesData = [
        ['number' => 1, 'title' => 'Issue 1', 'state' => 'open'],
        ['number' => 2, 'title' => 'PR 2', 'state' => 'open', 'pull_request' => []],
    ];

    $this->apiService->shouldReceive('listIssues')
        ->once()
        ->andReturn($issuesData);

    $this->apiService->shouldReceive('storeIssue')
        ->once(); // Only called for the issue, not the PR

    $this->apiService->shouldReceive('syncIssueToTask')
        ->never(); // Not called because monitoring is disabled

    $response = $this->postJson("/api/integrations/github/repos/{$repo->id}/sync-issues");

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 1);
});

test('sync issues handles api errors', function () {
    $repo = GitHubRepo::factory()->create();

    $this->apiService->shouldReceive('listIssues')
        ->once()
        ->andThrow(new Exception('GitHub API error'));

    $response = $this->postJson("/api/integrations/github/repos/{$repo->id}/sync-issues");

    $response->assertStatus(500);
});

// Sync pull requests tests
test('sync pull requests fetches and stores prs', function () {
    $repo = GitHubRepo::factory()->create();

    $prsData = [
        ['number' => 1, 'title' => 'PR 1', 'state' => 'open'],
        ['number' => 2, 'title' => 'PR 2', 'state' => 'open'],
    ];

    $this->apiService->shouldReceive('listPullRequests')
        ->once()
        ->andReturn($prsData);

    $this->apiService->shouldReceive('storePullRequest')
        ->twice();

    $response = $this->postJson("/api/integrations/github/repos/{$repo->id}/sync-prs");

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 2);
});

// List open PRs tests
test('list open prs returns all open pull requests', function () {
    $repo = GitHubRepo::factory()->create();
    $prs = GitHubPullRequest::factory()
        ->count(3)
        ->state(['state' => 'open'])
        ->for($repo, 'repo')
        ->create();

    $response = $this->getJson('/api/integrations/github/pull-requests');

    $response->assertStatus(200);
    $response->assertJsonCount(3, 'pull_requests');
    $response->assertJsonStructure([
        'pull_requests' => [
            '*' => [
                'id',
                'pr_number',
                'title',
                'author',
                'approval_status',
                'needs_approval',
                'checks_passed',
            ],
        ],
    ]);
});

// Approve PR tests
test('approve pr updates approval status', function () {
    $repo = GitHubRepo::factory()->create();
    $pr = GitHubPullRequest::factory()
        ->state(['approval_status' => 'pending', 'base_branch' => 'main'])
        ->for($repo, 'repo')
        ->create();

    $response = $this->postJson("/api/integrations/github/pull-requests/{$pr->id}/approve");

    $response->assertStatus(200);
    expect($pr->fresh()->approval_status)->toBe('approved');
});

test('approve pr auto-merges to develop when checks pass', function () {
    $repo = GitHubRepo::factory()->create();
    $pr = GitHubPullRequest::factory()
        ->state([
            'approval_status' => 'pending',
            'base_branch' => 'develop',
            'checks_passed' => true,
            'state' => 'open',
        ])
        ->for($repo, 'repo')
        ->create();

    $this->apiService->shouldReceive('mergePullRequest')
        ->once()
        ->with(Mockery::on(fn ($r) => $r->id === $repo->id), $pr->pr_number);

    $response = $this->postJson("/api/integrations/github/pull-requests/{$pr->id}/approve");

    $response->assertStatus(200);
    expect($pr->fresh()->state)->toBe('merged');
});

test('approve pr handles merge failure', function () {
    $repo = GitHubRepo::factory()->create();
    $pr = GitHubPullRequest::factory()
        ->state([
            'base_branch' => 'develop',
            'checks_passed' => true,
        ])
        ->for($repo, 'repo')
        ->create();

    $this->apiService->shouldReceive('mergePullRequest')
        ->once()
        ->andThrow(new Exception('Merge conflict'));

    $response = $this->postJson("/api/integrations/github/pull-requests/{$pr->id}/approve");

    $response->assertStatus(500);
});

// Reject PR tests
test('reject pr updates approval status', function () {
    $repo = GitHubRepo::factory()->create();
    $pr = GitHubPullRequest::factory()
        ->state(['approval_status' => 'pending'])
        ->for($repo, 'repo')
        ->create();

    $response = $this->postJson("/api/integrations/github/pull-requests/{$pr->id}/reject");

    $response->assertStatus(200);
    expect($pr->fresh()->approval_status)->toBe('rejected');
});
