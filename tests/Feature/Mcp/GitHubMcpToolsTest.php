<?php

use App\Jobs\SyncGitHubJob;
use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\ApproveGitHubPullRequestTool;
use App\Mcp\Tools\CreateGitHubIssueTool;
use App\Mcp\Tools\GetGitHubRepoTool;
use App\Mcp\Tools\LinkGitHubRepoToProjectTool;
use App\Mcp\Tools\ListGitHubIssuesTool;
use App\Mcp\Tools\ListGitHubPullRequestsTool;
use App\Mcp\Tools\ListGitHubReposTool;
use App\Mcp\Tools\RejectGitHubPullRequestTool;
use App\Mcp\Tools\SyncGitHubRepoTool;
use App\Models\Client;
use App\Models\GitHubInstallation;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\User;
use App\Services\GitHub\GitHubApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Acme Co']);
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'name' => 'Acme Portal',
        'slug' => 'acme-portal',
        'status' => 'active',
    ]);
    $this->installation = GitHubInstallation::factory()->create([
        'account_login' => 'zao',
        'account_type' => 'Organization',
    ]);
    $this->repo = GitHubRepo::factory()->create([
        'installation_id' => $this->installation->id,
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'owner' => 'zao',
        'name' => 'acme-portal',
        'full_name' => 'zao/acme-portal',
        'pushed_at' => now(),
    ]);
});

test('lists github repos with linked project context', function () {
    GitHubIssue::factory()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Fix onboarding copy',
    ]);

    GitHubPullRequest::factory()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Improve onboarding flow',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(ListGitHubReposTool::class, [
        'project_id' => $this->project->id,
    ]);

    $response->assertOk();

    expect($this->repo->fresh()->full_name)->toBe('zao/acme-portal');
});

test('gets github repo with recent issues and pull requests', function () {
    GitHubIssue::factory()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Broken checkout',
    ]);

    GitHubPullRequest::factory()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Fix checkout',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(GetGitHubRepoTool::class, [
        'full_name' => 'zao/acme-portal',
    ]);

    $response->assertOk();
    $response->assertSee('Broken checkout');
    $response->assertSee('Fix checkout');
});

test('lists github issues and pull requests by project', function () {
    GitHubIssue::factory()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Only this project issue',
    ]);
    GitHubPullRequest::factory()->targetsMain()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Only this project PR',
    ]);

    $issues = ZaoDashServer::actingAs($this->user)->tool(ListGitHubIssuesTool::class, [
        'project_id' => $this->project->id,
    ]);
    $prs = ZaoDashServer::actingAs($this->user)->tool(ListGitHubPullRequestsTool::class, [
        'project_id' => $this->project->id,
        'needs_approval' => true,
    ]);

    $issues->assertOk();
    $issues->assertSee('Only this project issue');
    $prs->assertOk();
    $prs->assertSee('Only this project PR');
});

test('links an existing github repo to a project', function () {
    $repo = GitHubRepo::factory()->create([
        'installation_id' => $this->installation->id,
        'client_id' => null,
        'project_id' => null,
        'full_name' => 'zao/another-repo',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(LinkGitHubRepoToProjectTool::class, [
        'project_id' => $this->project->id,
        'repo_id' => $repo->id,
    ]);

    $response->assertOk();

    $repo->refresh();
    expect($repo->project_id)->toBe($this->project->id)
        ->and($repo->client_id)->toBe($this->client->id);
});

test('creates a zao-only github repo record when linking by full name', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(LinkGitHubRepoToProjectTool::class, [
        'project_id' => $this->project->id,
        'full_name' => 'zao/new-linked-repo',
    ]);

    $response->assertOk();

    $repo = GitHubRepo::where('full_name', 'zao/new-linked-repo')->first();

    expect($repo)->not->toBeNull()
        ->and($repo->project_id)->toBe($this->project->id);
});

test('queues github repo sync through zao', function () {
    Queue::fake();

    $response = ZaoDashServer::actingAs($this->user)->tool(SyncGitHubRepoTool::class, [
        'id' => $this->repo->id,
    ]);

    $response->assertOk();
    $response->assertSee('Queued GitHub sync');

    Queue::assertPushed(SyncGitHubJob::class, function (SyncGitHubJob $job) {
        return $job->installationId === $this->installation->id
            && $job->repoId === $this->repo->id;
    });
});

test('approves and rejects github pull requests in zao', function () {
    $pr = GitHubPullRequest::factory()->targetsMain()->create([
        'repo_id' => $this->repo->id,
        'title' => 'Needs review',
        'approval_status' => 'pending',
    ]);

    $approve = ZaoDashServer::actingAs($this->user)->tool(ApproveGitHubPullRequestTool::class, [
        'id' => $pr->id,
        'note' => 'Looks good.',
    ]);

    $approve->assertOk();
    expect($pr->fresh()->approval_status)->toBe('approved');

    $reject = ZaoDashServer::actingAs($this->user)->tool(RejectGitHubPullRequestTool::class, [
        'id' => $pr->id,
        'reason' => 'Changed plan.',
    ]);

    $reject->assertOk();
    expect($pr->fresh()->approval_status)->toBe('rejected');
});

test('creates github issue through zao github app and syncs it to task', function () {
    $issue = GitHubIssue::factory()->make([
        'repo_id' => $this->repo->id,
        'issue_number' => 432,
        'title' => 'Document MCP flow',
        'body' => 'Add the operating notes.',
        'labels' => ['docs'],
    ]);

    $github = Mockery::mock(GitHubApiService::class);
    $github->shouldReceive('createIssue')
        ->once()
        ->withArgs(fn (GitHubRepo $repo, string $title, string $body, array $labels) => $repo->id === $this->repo->id
            && $title === 'Document MCP flow'
            && $body === 'Add the operating notes.'
            && $labels === ['docs'])
        ->andReturn([
            'id' => 987654,
            'number' => 432,
            'title' => 'Document MCP flow',
            'body' => 'Add the operating notes.',
            'state' => 'open',
            'labels' => [['name' => 'docs']],
            'assignees' => [],
        ]);
    $github->shouldReceive('storeIssue')
        ->once()
        ->andReturnUsing(function () use ($issue) {
            $issue->save();

            return $issue;
        });
    $github->shouldReceive('syncIssueToTask')->once();

    app()->instance(GitHubApiService::class, $github);

    $response = ZaoDashServer::actingAs($this->user)->tool(CreateGitHubIssueTool::class, [
        'repo_id' => $this->repo->id,
        'title' => 'Document MCP flow',
        'body' => 'Add the operating notes.',
        'labels' => ['docs'],
    ]);

    $response->assertOk();
    $response->assertSee('Created GitHub issue #432');
});
