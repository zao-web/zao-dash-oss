<?php

namespace Tests\Unit\Services\GitHub;

use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubApiService;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GitHubApiService $service;

    protected GitHubRepo $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $appService = $this->mock(GitHubAppService::class);
        $appService->shouldReceive('getInstallationToken')
            ->andReturn('test-token');

        $this->service = new GitHubApiService($appService);

        $installation = GitHubInstallation::factory()->create();
        $this->repo = GitHubRepo::factory()->create([
            'installation_id' => $installation->id,
            'full_name' => 'owner/repo',
        ]);
    }

    /** @test */
    public function it_lists_issues()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([
                [
                    'number' => 1,
                    'title' => 'Bug fix',
                    'state' => 'open',
                    'body' => 'Fix the bug',
                    'labels' => [],
                ],
            ], 200),
        ]);

        $issues = $this->service->listIssues($this->repo);

        $this->assertCount(1, $issues);
        $this->assertEquals('Bug fix', $issues[0]['title']);
    }

    /** @test */
    public function it_passes_parameters_to_list_issues()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([], 200),
        ]);

        $this->service->listIssues($this->repo, ['state' => 'closed', 'labels' => 'bug']);

        Http::assertSent(function ($request) {
            $query = $request->url();

            return str_contains($query, 'state=closed') &&
                   str_contains($query, 'labels=bug');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_list_issues()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([], 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to list issues');

        $this->service->listIssues($this->repo);
    }

    /** @test */
    public function it_gets_single_issue()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/1' => Http::response([
                'number' => 1,
                'title' => 'Issue #1',
                'state' => 'open',
            ], 200),
        ]);

        $issue = $this->service->getIssue($this->repo, 1);

        $this->assertEquals('Issue #1', $issue['title']);
    }

    /** @test */
    public function it_creates_issue()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues' => Http::response([
                'number' => 42,
                'title' => 'New issue',
                'state' => 'open',
            ], 201),
        ]);

        $issue = $this->service->createIssue(
            $this->repo,
            'New issue',
            'Issue body',
            ['bug', 'urgent']
        );

        $this->assertEquals(42, $issue['number']);

        Http::assertSent(function ($request) {
            return $request['title'] === 'New issue' &&
                   $request['body'] === 'Issue body' &&
                   $request['labels'] === ['bug', 'urgent'];
        });
    }

    /** @test */
    public function it_stores_issue()
    {
        $issueData = [
            'id' => 12345,
            'number' => 1,
            'title' => 'Test issue',
            'body' => 'Issue body',
            'state' => 'open',
            'labels' => [
                ['name' => 'bug'],
                ['name' => 'urgent'],
            ],
            'assignees' => [
                ['login' => 'john'],
            ],
        ];

        $issue = $this->service->storeIssue($this->repo, $issueData);

        $this->assertDatabaseHas('git_hub_issues', [
            'repo_id' => $this->repo->id,
            'issue_number' => 1,
            'title' => 'Test issue',
            'state' => 'open',
        ]);

        $this->assertEquals(['bug', 'urgent'], $issue->labels);
        $this->assertEquals(['john'], $issue->assignees);
    }

    /** @test */
    public function it_lists_pull_requests()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls*' => Http::response([
                [
                    'number' => 1,
                    'title' => 'Feature PR',
                    'state' => 'open',
                ],
            ], 200),
        ]);

        $prs = $this->service->listPullRequests($this->repo);

        $this->assertCount(1, $prs);
        $this->assertEquals('Feature PR', $prs[0]['title']);
    }

    /** @test */
    public function it_gets_single_pull_request()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls/1' => Http::response([
                'number' => 1,
                'title' => 'PR #1',
                'state' => 'open',
            ], 200),
        ]);

        $pr = $this->service->getPullRequest($this->repo, 1);

        $this->assertEquals('PR #1', $pr['title']);
    }

    /** @test */
    public function it_stores_pull_request()
    {
        $prData = [
            'id' => 54321,
            'number' => 1,
            'title' => 'Test PR',
            'body' => 'PR body',
            'state' => 'open',
            'base' => ['ref' => 'main'],
            'head' => ['ref' => 'feature'],
            'user' => ['login' => 'john'],
            'requested_reviewers' => [
                ['login' => 'jane'],
            ],
        ];

        $pr = $this->service->storePullRequest($this->repo, $prData);

        $this->assertDatabaseHas('git_hub_pull_requests', [
            'repo_id' => $this->repo->id,
            'pr_number' => 1,
            'title' => 'Test PR',
            'state' => 'open',
            'base_branch' => 'main',
            'head_branch' => 'feature',
            'author' => 'john',
        ]);

        $this->assertEquals(['jane'], $pr->reviewers);
    }

    /** @test */
    public function it_marks_merged_prs_correctly()
    {
        $prData = [
            'id' => 54321,
            'number' => 1,
            'title' => 'Merged PR',
            'state' => 'closed',
            'merged_at' => '2025-01-01T12:00:00Z',
            'base' => ['ref' => 'main'],
            'head' => ['ref' => 'feature'],
            'user' => ['login' => 'john'],
        ];

        $pr = $this->service->storePullRequest($this->repo, $prData);

        $this->assertEquals('merged', $pr->state);
        $this->assertNotNull($pr->merged_at);
    }

    /** @test */
    public function it_merges_pull_request()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls/1/merge' => Http::response([
                'sha' => 'abc123',
                'merged' => true,
            ], 200),
        ]);

        $result = $this->service->mergePullRequest($this->repo, 1, 'squash');

        $this->assertTrue($result['merged']);

        Http::assertSent(function ($request) {
            return $request['merge_method'] === 'squash';
        });
    }

    /** @test */
    public function it_adds_pr_comment()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/1/comments' => Http::response([
                'id' => 999,
                'body' => 'LGTM',
            ], 201),
        ]);

        $result = $this->service->addPrComment($this->repo, 1, 'LGTM');

        $this->assertEquals('LGTM', $result['body']);

        Http::assertSent(function ($request) {
            return $request['body'] === 'LGTM';
        });
    }

    /** @test */
    public function it_sets_repo_secret()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/actions/secrets/public-key' => Http::response([
                'key_id' => 'key123',
                'key' => base64_encode(random_bytes(32)),
            ], 200),
            'api.github.com/repos/owner/repo/actions/secrets/MY_SECRET' => Http::response([], 204),
        ]);

        $this->service->setRepoSecret($this->repo, 'MY_SECRET', 'secret-value');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/actions/secrets/MY_SECRET') &&
                   isset($request['encrypted_value']) &&
                   isset($request['key_id']);
        });
    }

    /** @test */
    public function it_creates_workflow_file()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/contents/.github/workflows/deploy.yml' => Http::sequence()
                ->push([], 404) // File doesn't exist
                ->push(['content' => 'created'], 201), // File created
        ]);

        $this->repo->default_branch = 'main';
        $this->repo->save();

        $result = $this->service->createWorkflowFile($this->repo, 'workflow content', 'deploy.yml');

        $this->assertArrayHasKey('content', $result);

        Http::assertSent(function ($request) {
            return $request['message'] === 'Add deployment workflow (via Zao Dash)' &&
                   $request['branch'] === 'main' &&
                   base64_decode($request['content']) === 'workflow content';
        });
    }

    /** @test */
    public function it_updates_existing_workflow_file()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/contents/.github/workflows/deploy.yml' => Http::sequence()
                ->push(['sha' => 'existing-sha'], 200) // File exists
                ->push(['content' => 'updated'], 200), // File updated
        ]);

        $this->repo->default_branch = 'main';
        $this->repo->save();

        $this->service->createWorkflowFile($this->repo, 'new content', 'deploy.yml');

        Http::assertSent(function ($request) {
            return isset($request['sha']) && $request['sha'] === 'existing-sha';
        });
    }

    /** @test */
    public function it_dispatches_a_workflow()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/actions/workflows/deploy.yml/dispatches' => Http::response([], 204),
        ]);

        $this->service->dispatchWorkflow($this->repo, 'deploy.yml', 'feature/review-branch');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/actions/workflows/deploy.yml/dispatches'
                && $request['ref'] === 'feature/review-branch';
        });
    }

    /** @test */
    public function it_reruns_a_workflow_run()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/actions/runs/123/rerun' => Http::response([], 201),
        ]);

        $this->service->rerunWorkflowRun($this->repo, 123);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/repo/actions/runs/123/rerun';
        });
    }

    /** @test */
    public function it_sends_github_auth_headers()
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([], 200),
        ]);

        $this->service->listIssues($this->repo);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                   $request->hasHeader('Accept', 'application/vnd.github+json');
        });
    }
}
