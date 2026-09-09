<?php

use App\Jobs\SyncGitHubJob;
use App\Models\GitHubInstallation;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    SyncGitHubJob::dispatch();

    Queue::assertPushed(SyncGitHubJob::class);
});

test('job can be dispatched with parameters', function () {
    Queue::fake();

    SyncGitHubJob::dispatch(installationId: 1, repoId: 2);

    Queue::assertPushed(SyncGitHubJob::class, function ($job) {
        return $job->installationId === 1 && $job->repoId === 2;
    });
});

test('handle syncs all active installations', function () {
    $inst1 = GitHubInstallation::factory()->create(['is_active' => true]);
    $inst2 = GitHubInstallation::factory()->create(['is_active' => true]);
    $inactive = GitHubInstallation::factory()->create(['is_active' => false]);

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->twice()->andReturn([]);

    $job = new SyncGitHubJob;
    $job->handle($githubService);
});

test('handle syncs repositories for installation', function () {
    $installation = GitHubInstallation::factory()->create(['is_active' => true]);

    $repos = [
        [
            'id' => 123,
            'name' => 'test-repo',
            'full_name' => 'user/test-repo',
            'html_url' => 'https://github.com/user/test-repo',
            'clone_url' => 'https://github.com/user/test-repo.git',
            'default_branch' => 'main',
            'private' => false,
            'archived' => false,
            'language' => 'PHP',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'open_issues_count' => 5,
        ],
    ];

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->once()->andReturn($repos);

    $job = new SyncGitHubJob(installationId: $installation->id);
    $job->handle($githubService);

    expect(GitHubRepo::count())->toBe(1);

    $repo = GitHubRepo::first();
    expect($repo->github_id)->toBe(123)
        ->and($repo->name)->toBe('test-repo')
        ->and($repo->language)->toBe('PHP');
});

test('handle syncs issues for tracked repos', function () {
    $installation = GitHubInstallation::factory()->create(['is_active' => true]);
    $trackedRepo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => true,
        'full_name' => 'user/repo',
    ]);
    $untrackedRepo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => false,
    ]);

    $issues = [
        [
            'id' => 1,
            'number' => 100,
            'title' => 'Bug fix',
            'body' => 'Description',
            'state' => 'open',
            'html_url' => 'https://github.com/user/repo/issues/100',
            'user' => ['login' => 'john', 'avatar_url' => 'https://avatar.url'],
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-02T00:00:00Z',
        ],
    ];

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->andReturn([]);
    $githubService->shouldReceive('listIssues')->once()->andReturn($issues);
    $githubService->shouldReceive('listPullRequests')->once()->andReturn([]);

    $job = new SyncGitHubJob(installationId: $installation->id);
    $job->handle($githubService);

    expect(GitHubIssue::count())->toBe(1);
    expect(GitHubIssue::first()->title)->toBe('Bug fix');
});

test('handle skips pull requests in issues API', function () {
    $installation = GitHubInstallation::factory()->create(['is_active' => true]);
    $repo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => true,
        'full_name' => 'user/repo',
    ]);

    $issues = [
        ['id' => 1, 'number' => 1, 'title' => 'Issue', 'pull_request' => ['url' => 'pr-url']],
        ['id' => 2, 'number' => 2, 'title' => 'Real Issue'],
    ];

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->andReturn([]);
    $githubService->shouldReceive('listIssues')->once()->andReturn($issues);
    $githubService->shouldReceive('listPullRequests')->once()->andReturn([]);

    $job = new SyncGitHubJob(installationId: $installation->id);
    $job->handle($githubService);

    // Should only create the real issue, not the PR
    expect(GitHubIssue::count())->toBe(1);
    expect(GitHubIssue::first()->title)->toBe('Real Issue');
});

test('handle syncs pull requests for tracked repos', function () {
    $installation = GitHubInstallation::factory()->create(['is_active' => true]);
    $repo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => true,
        'full_name' => 'user/repo',
    ]);

    $prs = [
        [
            'id' => 1,
            'number' => 10,
            'title' => 'Feature PR',
            'body' => 'New feature',
            'state' => 'open',
            'html_url' => 'https://github.com/user/repo/pull/10',
            'user' => ['login' => 'jane', 'avatar_url' => 'avatar'],
            'head' => ['ref' => 'feature', 'sha' => 'abc123'],
            'base' => ['ref' => 'main'],
            'draft' => false,
            'additions' => 100,
            'deletions' => 50,
            'changed_files' => 5,
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-02T00:00:00Z',
        ],
    ];

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->andReturn([]);
    $githubService->shouldReceive('listIssues')->once()->andReturn([]);
    $githubService->shouldReceive('listPullRequests')->once()->andReturn($prs);

    $job = new SyncGitHubJob(installationId: $installation->id);
    $job->handle($githubService);

    expect(GitHubPullRequest::count())->toBe(1);

    $pr = GitHubPullRequest::first();
    expect($pr->title)->toBe('Feature PR')
        ->and($pr->head_branch)->toBe('feature')
        ->and($pr->additions)->toBe(100);
});

test('handle updates installation last_synced_at', function () {
    $installation = GitHubInstallation::factory()->create([
        'is_active' => true,
        'last_synced_at' => null,
    ]);

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->andReturn([]);

    $job = new SyncGitHubJob(installationId: $installation->id);
    $job->handle($githubService);

    $installation->refresh();
    expect($installation->last_synced_at)->not->toBeNull();
});

test('handle syncs specific repo when repoId provided', function () {
    $installation = GitHubInstallation::factory()->create(['is_active' => true]);
    $targetRepo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => true,
        'full_name' => 'user/target',
    ]);
    $otherRepo = GitHubRepo::factory()->create([
        'installation_id' => $installation->id,
        'is_tracked' => true,
    ]);

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')->andReturn([]);
    $githubService->shouldReceive('listIssues')
        ->once()
        ->with(Mockery::any(), 'user/target', Mockery::any())
        ->andReturn([]);
    $githubService->shouldReceive('listPullRequests')
        ->once()
        ->with(Mockery::any(), 'user/target')
        ->andReturn([]);

    $job = new SyncGitHubJob(installationId: $installation->id, repoId: $targetRepo->id);
    $job->handle($githubService);
});

test('handle logs errors and continues', function () {
    Log::spy();

    $inst1 = GitHubInstallation::factory()->create(['is_active' => true]);
    $inst2 = GitHubInstallation::factory()->create(['is_active' => true]);

    $githubService = Mockery::mock(GitHubService::class);
    $githubService->shouldReceive('listRepositories')
        ->twice()
        ->andReturnUsing(function () {
            static $call = 0;
            if ($call++ === 0) {
                throw new Exception('API error');
            }

            return [];
        });

    $job = new SyncGitHubJob;
    $job->handle($githubService);

    Log::shouldHaveReceived('error')
        ->with('GitHub sync failed', Mockery::any());
});

test('job implements ShouldQueue interface', function () {
    $job = new SyncGitHubJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(SyncGitHubJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
