<?php

use App\Models\AgentRun;
use App\Models\Client;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\RetainerPeriod;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Services\Reports\RetainerHealthService;
use App\Services\Reports\RetainerReportPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts PRs merged inside the period only', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // In-period merged
    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'merged_at' => '2026-04-15 12:00:00',
    ]);
    // Out-of-period merged (March)
    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'merged_at' => '2026-03-15 12:00:00',
    ]);
    // Out-of-period merged (May)
    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'merged_at' => '2026-05-15 12:00:00',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['prs_merged'])->toBe(1);
});

it('only counts PRs from repos linked to the client', function () {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $repoA = GitHubRepo::factory()->create(['client_id' => $clientA->id]);
    $repoB = GitHubRepo::factory()->create(['client_id' => $clientB->id]);

    $period = RetainerPeriod::factory()->create([
        'client_id' => $clientA->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    GitHubPullRequest::factory()->merged()->create(['repo_id' => $repoA->id, 'merged_at' => '2026-04-10']);
    GitHubPullRequest::factory()->merged()->create(['repo_id' => $repoB->id, 'merged_at' => '2026-04-10']);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['prs_merged'])->toBe(1);
});

it('counts Slack messages tagged to the client and splits internal vs external', function () {
    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    SlackMessage::factory()->count(3)->create([
        'client_id' => $client->id,
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_is_external' => true,
        'created_at' => '2026-04-15 09:00:00',
    ]);
    SlackMessage::factory()->count(5)->create([
        'client_id' => $client->id,
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_is_external' => false,
        'created_at' => '2026-04-16 09:00:00',
    ]);
    // Out-of-period
    SlackMessage::factory()->create([
        'client_id' => $client->id,
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'created_at' => '2026-03-30 09:00:00',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['slack']['total_messages'])->toBe(8);
    expect($activity['slack']['external_messages'])->toBe(3);
    expect($activity['slack']['internal_messages'])->toBe(5);
});

it('counts issues closed inside the period only', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'state' => 'closed',
        'closed_at' => '2026-04-20 12:00:00',
    ]);
    GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'state' => 'closed',
        'closed_at' => '2026-03-20 12:00:00',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['issues_closed'])->toBe(1);
});

it('renders the Period activity section in the report when there is activity', function () {
    $client = Client::factory()->create(['name' => 'Acme Co']);
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id, 'name' => 'acme-app']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'title' => 'Add login screen',
        'merged_at' => '2026-04-12 12:00:00',
    ]);

    $html = app(RetainerReportPdfGenerator::class)->renderHtml($period);

    expect($html)->toContain('What happened this period');
    expect($html)->toContain('Add login screen');
    expect($html)->toContain('acme-app');
});

it('counts Slack messages logged on the last day of the period (date boundary fix)', function () {
    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // Message at 14:32 on the LAST day — would be excluded without the
    // endOfDay normalization since Carbon::parse('2026-04-30') => 00:00:00.
    SlackMessage::factory()->create([
        'client_id' => $client->id,
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'created_at' => '2026-04-30 14:32:18',
    ]);

    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30'),
        persist: false,
    );

    expect($snapshot['period_activity']['slack']['total_messages'])->toBe(1);
});

it('isAiAuthor recognises common AI commit authors', function () {
    $svc = app(\App\Services\GitHub\CommitEffortEstimationService::class);
    expect($svc->isAiAuthor('claude'))->toBeTrue();
    expect($svc->isAiAuthor('Claude'))->toBeTrue();
    expect($svc->isAiAuthor('claude-code-bot'))->toBeTrue();
    expect($svc->isAiAuthor('github-actions[bot]'))->toBeTrue();
    expect($svc->isAiAuthor('dependabot[bot]'))->toBeTrue();
    expect($svc->isAiAuthor('jsainton-godaddy'))->toBeFalse();
    expect($svc->isAiAuthor('Owner User'))->toBeFalse();
    expect($svc->isAiAuthor(null))->toBeFalse();
});

it('counts default-branch commits via the GitHub API and includes them in the snapshot', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'name' => 'acme-app',
        'full_name' => 'acme/acme-app',
        'default_branch' => 'main',
    ]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    $api = $this->mock(\App\Services\GitHub\GitHubApiService::class);
    $api->shouldReceive('listCommits')
        ->once()
        ->andReturn([
            [
                'sha' => 'abcdef1234567890',
                'commit' => [
                    'message' => 'Fix login redirect bug',
                    'author' => ['name' => 'Justin'],
                    'committer' => ['date' => '2026-04-10T12:00:00Z'],
                ],
                'author' => ['login' => 'jsainton'],
            ],
            [
                'sha' => '1234567890abcdef',
                'commit' => [
                    'message' => "Bump deps\n\nFull release notes...",
                    'author' => ['name' => 'Justin'],
                    'committer' => ['date' => '2026-04-15T09:00:00Z'],
                ],
                'author' => ['login' => 'jsainton'],
            ],
        ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['commits'])->toBe(2);
    expect($activity['github']['recent_commits'])->toHaveCount(2);
    expect($activity['github']['recent_commits'][0]['message'])->toBe('Bump deps');
});

it('survives GitHub API failures when fetching commits', function () {
    $client = Client::factory()->create();
    GitHubRepo::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    $api = $this->mock(\App\Services\GitHub\GitHubApiService::class);
    $api->shouldReceive('listCommits')
        ->andThrow(new \RuntimeException('GitHub API down'));

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['commits'])->toBe(0);
});

it('classifies Slack messages as from-client when sender is not in the internal user list', function () {
    config()->set('services.slack.internal_user_ids', ['U_TEAM_1', 'U_TEAM_2']);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // 2 internal, 3 client (none of which trip user_is_external)
    foreach (['U_TEAM_1', 'U_TEAM_2'] as $uid) {
        SlackMessage::factory()->create([
            'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
            'user_id' => $uid, 'user_is_external' => false, 'created_at' => '2026-04-10',
        ]);
    }
    foreach (['U_CLIENT_A', 'U_CLIENT_A', 'U_CLIENT_B'] as $uid) {
        SlackMessage::factory()->create([
            'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
            'user_id' => $uid, 'user_is_external' => false, 'created_at' => '2026-04-10',
        ]);
    }

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['slack']['internal_messages'])->toBe(2);
    expect($activity['slack']['external_messages'])->toBe(3);
});

it('falls back to user_is_external when no internal list is configured', function () {
    config()->set('services.slack.internal_user_ids', []);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
    ]);

    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_is_external' => true, 'created_at' => '2026-04-10',
    ]);
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_is_external' => false, 'created_at' => '2026-04-10',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['slack']['external_messages'])->toBe(1);
    expect($activity['slack']['internal_messages'])->toBe(1);
});

it('emails the retainer report to the client billing email', function () {
    \Illuminate\Support\Facades\Mail::fake();

    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['billing_email' => 'ceo@acme.test']);
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);

    $this->actingAs($user)
        ->post("/retainers/{$period->id}/report/send")
        ->assertRedirect();

    \Illuminate\Support\Facades\Mail::assertSent(
        \App\Mail\RetainerReportMail::class,
        fn ($mail) => $mail->hasTo('ceo@acme.test')
    );
});

it('resolves internal Slack IDs from users + config + owner_user_id', function () {
    config()->set('services.slack.internal_user_ids', ['U_CONFIG_1']);
    config()->set('services.slack.owner_user_id', 'U_OWNER');

    \App\Models\User::factory()->create(['slack_user_id' => 'U_USER_1']);
    \App\Models\User::factory()->create(['slack_user_id' => 'U_USER_2']);
    \App\Models\User::factory()->create(['slack_user_id' => null]);

    $ids = app(RetainerHealthService::class)->resolveInternalSlackIds();

    expect($ids)->toContain('U_USER_1', 'U_USER_2', 'U_CONFIG_1', 'U_OWNER');
    expect($ids)->toHaveCount(4);
});

it('estimates Slack on-task hours per internal user per day', function () {
    config()->set('services.slack.internal_user_ids', ['U_TEAM']);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
    ]);

    // Session 1 (Apr 10 morning): 09:00 + 09:10 — within 30min gap, one session.
    // Span 10 min + 5min buffer = 15 min.
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-10 09:00:00',
    ]);
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-10 09:10:00',
    ]);
    // Session 2 (Apr 10 afternoon, 5h later — new session). Single message
    // gets min 5 min.
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-10 14:00:00',
    ]);
    // Session 3 (Apr 11): lone message → 5 min.
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-11 10:00:00',
    ]);

    // Mock the commit estimator so it doesn't try to hit GitHub.
    $commitEstimator = $this->mock(\App\Services\GitHub\CommitEffortEstimationService::class);

    $est = app(RetainerHealthService::class)->aggregateEstimatedHours(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
        $commitEstimator,
    );

    // 15min + 5min + 5min = 25min = ~0.42 hours
    expect($est['slack_hours'])->toBeGreaterThan(0.4);
    expect($est['slack_hours'])->toBeLessThan(0.5);
    expect($est['slack_by_user'][0]['sessions'])->toBe(3);
});

it('snapshot falls back to estimated hours when time_entries is empty', function () {
    config()->set('services.slack.internal_user_ids', ['U_TEAM']);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        'hours_included' => 10,
    ]);

    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-10 09:00:00',
    ]);
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'created_at' => '2026-04-10 11:00:00',
    ]);

    // Mock the commit estimator so it doesn't try to hit GitHub.
    $this->mock(\App\Services\GitHub\CommitEffortEstimationService::class);

    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30'),
        persist: false,
    );

    expect($snapshot['human_hours_source'])->toBe('estimated');
    expect($snapshot['human_hours'])->toBeGreaterThan(0);
    expect($snapshot['estimated_hours'])->not->toBeNull();
});

it('uses tracked hours when time_entries exist (does not fall back to estimation)', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id, 'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
    ]);

    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 4.5,
        'spent_date' => '2026-04-10',
    ]);

    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30'),
        persist: false,
    );

    expect($snapshot['human_hours_source'])->toBe('tracked');
    expect($snapshot['human_hours'])->toBe(4.5);
    expect($snapshot['estimated_hours'])->toBeNull();
});

it('falls back to user_access_token when bot token gets channel_not_found', function () {
    \Illuminate\Support\Facades\Http::fake([
        'slack.com/api/conversations.history*' => function (\Illuminate\Http\Client\Request $request) {
            $auth = $request->header('Authorization')[0] ?? '';
            // bot token returns channel_not_found; user token returns messages
            if (str_contains($auth, 'bot-token')) {
                return \Illuminate\Support\Facades\Http::response(['ok' => false, 'error' => 'channel_not_found']);
            }

            return \Illuminate\Support\Facades\Http::response([
                'ok' => true,
                'messages' => [['user' => 'U1', 'text' => 'hi', 'ts' => '1.0']],
            ]);
        },
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'access_token' => 'bot-token',
        'user_access_token' => 'user-token',
    ]);

    $result = app(\App\Services\Slack\SlackService::class)
        ->getChannelHistoryWithError($workspace, 'C00EXAMPLE01');

    expect($result['ok'])->toBeTrue();
    expect($result['messages'])->toHaveCount(1);
});

it('does not retry when user_access_token is null', function () {
    \Illuminate\Support\Facades\Http::fake([
        'slack.com/api/conversations.history*' => \Illuminate\Support\Facades\Http::response([
            'ok' => false, 'error' => 'channel_not_found',
        ]),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'access_token' => 'bot-token',
        'user_access_token' => null,
    ]);

    $result = app(\App\Services\Slack\SlackService::class)
        ->getChannelHistoryWithError($workspace, 'C00EXAMPLE01');

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toBe('channel_not_found');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

it('revokeAccess preserves channels and messages (does not delete workspace)', function () {
    \Illuminate\Support\Facades\Http::fake([
        'slack.com/api/auth.revoke*' => \Illuminate\Support\Facades\Http::response(['ok' => true]),
    ]);

    $workspace = SlackWorkspace::factory()->create([
        'access_token' => 'xoxb-bot',
        'user_access_token' => 'xoxp-user',
        'authed_user_id' => 'U_HUMAN',
        'is_active' => true,
    ]);
    $channel = \App\Models\SlackChannel::factory()->create(['workspace_id' => $workspace->id]);

    app(\App\Services\Slack\SlackOAuthService::class)->revokeAccess($workspace);

    // Workspace row survives — just marked inactive with user token cleared
    $fresh = $workspace->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->is_active)->toBeFalse();
    expect($fresh->user_access_token)->toBeNull();
    expect($fresh->authed_user_id)->toBeNull();
    // Channels survive
    expect(\App\Models\SlackChannel::find($channel->id))->not->toBeNull();
});

it('captures authed_user.access_token from OAuth response', function () {
    $oauth = app(\App\Services\Slack\SlackOAuthService::class);
    $workspace = $oauth->storeWorkspace([
        'access_token' => 'xoxb-bot-token',
        'bot_user_id' => 'BOT123',
        'team' => ['id' => 'T1', 'name' => 'Zao'],
        'authed_user' => [
            'id' => 'U_HUMAN',
            'access_token' => 'xoxp-user-token',
        ],
    ]);

    expect($workspace->user_access_token)->toBe('xoxp-user-token');
    expect($workspace->authed_user_id)->toBe('U_HUMAN');
});

it('slack:track-channel registers a group DM and links it to a client', function () {
    $client = Client::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $workspace = SlackWorkspace::factory()->create(['is_primary' => true]);

    $this->artisan('slack:track-channel', [
        'slack_id' => 'C00EXAMPLE01',
        'client' => 'acme',
        '--dm' => true,
    ])->assertSuccessful();

    $channel = \App\Models\SlackChannel::where('slack_id', 'C00EXAMPLE01')->first();
    expect($channel)->not->toBeNull();
    expect($channel->client_id)->toBe($client->id);
    expect($channel->is_dm)->toBeTrue();
    expect($channel->is_monitored)->toBeTrue();
});

it('users:link-slack command sets a slack_user_id', function () {
    $user = \App\Models\User::factory()->create(['email' => 'owner@example.com', 'slack_user_id' => null]);

    $this->artisan('users:link-slack', ['user' => 'owner@example.com', 'slack_user_id' => 'U00EXAMPLE01'])
        ->assertSuccessful();

    expect($user->fresh()->slack_user_id)->toBe('U00EXAMPLE01');
});

it('finds repos linked to a client via project, not just direct client_id', function () {
    $client = Client::factory()->create();
    $project = \App\Models\Project::factory()->create(['client_id' => $client->id]);

    // Direct
    $repoDirect = GitHubRepo::factory()->create(['client_id' => $client->id]);
    // Project-mediated
    $repoViaProject = GitHubRepo::factory()->create(['client_id' => null, 'project_id' => $project->id]);
    // Unrelated
    GitHubRepo::factory()->create(['client_id' => null, 'project_id' => null]);

    $repos = app(RetainerHealthService::class)->reposForClient($client->id);

    expect($repos->pluck('id')->all())->toContain($repoDirect->id, $repoViaProject->id);
    expect($repos)->toHaveCount(2);
});

it('counts PRs for repos linked via project, not just direct client_id', function () {
    $client = Client::factory()->create();
    $project = \App\Models\Project::factory()->create(['client_id' => $client->id]);
    $repo = GitHubRepo::factory()->create(['client_id' => null, 'project_id' => $project->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'merged_at' => '2026-04-15 12:00:00',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['prs_merged'])->toBe(1);
});

it('skips commit fetch for repos with no installation_id rather than throwing', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'installation_id' => null,
    ]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // The API mock should NOT be called for installation-less repos.
    $api = $this->mock(\App\Services\GitHub\GitHubApiService::class);
    $api->shouldNotReceive('listCommits');

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['github']['commits'])->toBe(0);
});

it('extracts client email domains from billing_email and contacts', function () {
    $client = Client::factory()->create(['billing_email' => 'ap@acme.test']);
    $client->contacts()->create(['name' => 'Cory', 'email' => 'cory@acme.test', 'is_primary' => true]);
    $client->contacts()->create(['name' => 'Alex', 'email' => 'alex@billing.acme.test']);

    $domains = app(RetainerHealthService::class)->clientEmailDomains($client->fresh());

    expect($domains)->toContain('acme.test', 'billing.acme.test');
});

it('matches untagged emails to the client by sender domain', function () {
    $client = Client::factory()->create();
    $client->contacts()->create(['name' => 'Cory', 'email' => 'cory@example-client.test']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'hours_included' => 20,
    ]);

    // Untagged inbound from client domain
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'cory@example-client.test',
        'subject' => 'Update Medicus job listings',
        'created_at' => '2026-04-15 09:00:00',
    ]);
    // Untagged outbound to client domain
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'owner@example.com',
        'to_addresses' => ['cory@example-client.test'],
        'subject' => 'Re: Update Medicus',
        'created_at' => '2026-04-15 10:00:00',
    ]);
    // Unrelated email — should NOT count
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'random@other.test',
        'to_addresses' => ['owner@example.com'],
        'created_at' => '2026-04-15 10:00:00',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['email']['inbound_count'])->toBe(1);
    expect($activity['email']['outbound_count'])->toBe(1);
});

it('estimates email hours: 10min inbound + 5min outbound (or 30min if body is long)', function () {
    config()->set('services.slack.internal_user_ids', []);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $client->contacts()->create(['name' => 'Cory', 'email' => 'cory@example-client.test']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // Short inbound — 10 min
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'cory@example-client.test',
        'body_text' => 'Quick question?',
        'created_at' => '2026-04-15',
    ]);
    // Long inbound — 30 min
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'cory@example-client.test',
        'body_text' => str_repeat('long ', 400),
        'created_at' => '2026-04-15',
    ]);
    // Outbound reply — 5 min
    \App\Models\Email::factory()->create([
        'client_id' => null,
        'from_address' => 'owner@example.com',
        'to_addresses' => ['cory@example-client.test'],
        'created_at' => '2026-04-15',
    ]);

    // Mock commit estimator (we don't need it for this test)
    $this->mock(\App\Services\GitHub\CommitEffortEstimationService::class);

    $est = app(RetainerHealthService::class)->aggregateEstimatedHours(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    // 10/60 + 30/60 + 5/60 = 45/60 = 0.75
    expect($est['email_hours'])->toBe(0.75);
    expect($est['email_counts'])->toBe(['inbound' => 2, 'outbound' => 1]);
});

it('inertia retainer snapshot endpoint returns a redirect, not JSON', function () {
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $period = RetainerPeriod::factory()->create();

    $this->actingAs($user)
        ->post("/retainers/{$period->id}/snapshot")
        ->assertRedirect();
});

it('refresh clears the commit cache and re-persists the snapshot', function () {
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
        'health_status' => 'critical',
    ]);

    // Key mirrors the aggregator: the actual UTC window queried (Pacific-bounded).
    $cacheKey = sprintf(
        'retainer.commits.%d.%s.%s',
        $repo->id,
        $period->windowStart()->toDateString(),
        $period->windowEnd()->toDateString(),
    );
    \Illuminate\Support\Facades\Cache::put($cacheKey, [['sha' => 'stale']], 3600);
    expect(\Illuminate\Support\Facades\Cache::get($cacheKey))->toBe([['sha' => 'stale']]);

    // Mock the API so the refresh doesn't actually hit GitHub.
    $api = $this->mock(\App\Services\GitHub\GitHubApiService::class);
    $api->shouldReceive('listCommits')->andReturn([]);

    $this->actingAs($user)
        ->post("/retainers/{$period->id}/report/refresh")
        ->assertRedirect();

    // After refresh, the cache holds the fresh API result, not the stale value.
    expect(\Illuminate\Support\Facades\Cache::get($cacheKey))->toBe([]);
    expect($period->fresh()->health_status)->not->toBe('critical');
});

it('refresh queues the job and returns immediately with a running status', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $period = RetainerPeriod::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->actingAs($user)
        ->post("/retainers/{$period->id}/report/refresh")
        ->assertRedirect();

    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\RefreshRetainerReportJob::class,
        fn ($job) => $job->period->id === $period->id,
    );

    $status = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RefreshRetainerReportJob::statusKey($period->id));
    expect($status['state'] ?? null)->toBe('running');
});

it('refresh does not stack a second job while one is running', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $period = RetainerPeriod::factory()->create(['client_id' => Client::factory()->create()->id]);

    \Illuminate\Support\Facades\Cache::put(
        \App\Jobs\RefreshRetainerReportJob::statusKey($period->id),
        ['state' => 'running', 'started_at' => now()->toIso8601String()],
        600,
    );

    $this->actingAs($user)
        ->post("/retainers/{$period->id}/report/refresh")
        ->assertRedirect();

    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

it('refresh job records a done status with the topic count', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);

    $this->mock(\App\Services\Reports\RetainerNarrativeService::class, function ($m) {
        $m->shouldReceive('buildNarrative')->once()->andReturn([
            'topics' => [
                ['title' => 'A', 'summary' => '', 'estimated_hours' => 1.0, 'status' => 'completed'],
                ['title' => 'B', 'summary' => '', 'estimated_hours' => 2.0, 'status' => 'completed'],
            ],
            'value_summary' => 'Shipped.',
            'total_estimated_hours' => 3.0,
            'generated_at' => now()->toIso8601String(),
            'warnings' => [],
        ]);
    });

    (new \App\Jobs\RefreshRetainerReportJob($period))->handle(
        app(\App\Services\Reports\RetainerHealthService::class),
        app(\App\Services\Reports\RetainerNarrativeService::class),
        app(\App\Services\Reports\RetainerReportPdfGenerator::class),
    );

    $status = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RefreshRetainerReportJob::statusKey($period->id));
    expect($status['state'] ?? null)->toBe('done');
    expect($status['message'] ?? '')->toContain('2 topic(s)');
});

it('admin view shows a terminal refresh status once, then clears it', function () {
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $period = RetainerPeriod::factory()->create(['client_id' => Client::factory()->create()->id]);
    $statusKey = \App\Jobs\RefreshRetainerReportJob::statusKey($period->id);

    \Illuminate\Support\Facades\Cache::put($statusKey, [
        'state' => 'done',
        'message' => 'Report data refreshed. Narrative regenerated with 3 topic(s).',
        'finished_at' => now()->toIso8601String(),
    ], 600);

    $this->actingAs($user)
        ->get("/retainers/{$period->id}/report")
        ->assertOk()
        ->assertSee('Narrative regenerated with 3 topic(s).');

    // Cleared after one display — a later visit shows no banner.
    expect(\Illuminate\Support\Facades\Cache::get($statusKey))->toBeNull();
});

it('persists narrative topics as TimeEntry rows with source=ai_estimated', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);

    app(\App\Services\Reports\RetainerNarrativeService::class)->persistAsTimeEntries($period, [
        ['title' => 'Posted-date refresh', 'summary' => 'Salesforce job stale dates', 'estimated_hours' => 7.5, 'status' => 'completed'],
        ['title' => 'IP block unblocking', 'summary' => 'Whitelist Cory IP', 'estimated_hours' => 0.5, 'status' => 'completed'],
        ['title' => 'zero-hour topic', 'summary' => 'Should be skipped', 'estimated_hours' => 0, 'status' => 'discussion_only'],
    ]);

    $entries = \App\Models\TimeEntry::where('retainer_period_id', $period->id)->get();
    expect($entries)->toHaveCount(2);
    expect($entries->pluck('source')->unique()->all())->toBe(['ai_estimated']);
    expect((float) $entries->sum('hours'))->toBe(8.0);
});

it('preserves existing AI entries when buildNarrative LLM call fails (warning)', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);

    // Seed a previously-good AI entry from an earlier successful narrative.
    \App\Models\TimeEntry::create([
        'client_id' => $client->id,
        'hours' => 4.0,
        'source' => 'ai_estimated',
        'retainer_period_id' => $period->id,
        'spent_date' => $period->period_start,
        'notes' => 'Previously generated topic',
        'is_billable' => true,
        'is_billed' => false,
    ]);

    // Force the next narrative call to "fail" (returns warning + no topics).
    $svc = $this->partialMock(\App\Services\Reports\RetainerNarrativeService::class, function ($m) {
        $m->shouldAllowMockingProtectedMethods();
        $m->shouldReceive('compute')->andReturn([
            'topics' => [],
            'value_summary' => '',
            'total_estimated_hours' => 0.0,
            'generated_at' => now()->toIso8601String(),
            'warnings' => ['LLM returned unparseable response.'],
        ]);
    });

    \Illuminate\Support\Facades\Cache::forget('retainer.narrative.'.$period->id);
    $svc->buildNarrative($period, force: true);

    // The seeded AI entry must survive the failed regen, not vanish.
    $entries = \App\Models\TimeEntry::where('retainer_period_id', $period->id)->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->notes)->toBe('Previously generated topic');
});

it('persists exactly one entry per topic, dated at the topic end (delivery) date', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    app(\App\Services\Reports\RetainerNarrativeService::class)->persistAsTimeEntries($period, [
        // Multi-day span → single entry at end_date with the FULL hours.
        ['title' => 'Mid-month work', 'summary' => '', 'estimated_hours' => 3.0, 'status' => 'completed', 'start_date' => '2026-04-14', 'end_date' => '2026-04-17'],
        // Single-day → entry on that day.
        ['title' => 'Late-month work', 'summary' => '', 'estimated_hours' => 1.5, 'status' => 'completed', 'start_date' => '2026-04-28'],
        // No dates → falls back to period_start.
        ['title' => 'No-date topic falls back', 'summary' => '', 'estimated_hours' => 0.5, 'status' => 'completed'],
        // Out-of-band date → clamped to period_start.
        ['title' => 'Out-of-band date clamps', 'summary' => '', 'estimated_hours' => 1.0, 'status' => 'completed', 'start_date' => '2026-06-15'],
        // Multi-week span → still one entry, dated at delivery.
        ['title' => 'Multi-week feature', 'summary' => '', 'estimated_hours' => 8.0, 'status' => 'completed', 'start_date' => '2026-04-05', 'end_date' => '2026-04-20'],
    ]);

    $entries = \App\Models\TimeEntry::where('retainer_period_id', $period->id)
        ->orderBy('spent_date')->orderBy('id')->get();

    // One row per topic — repeated descriptions on a client-facing timesheet
    // read as duplicate billing, so hours are never split across dates.
    expect($entries)->toHaveCount(5);
    expect($entries->pluck('notes')->duplicates())->toBeEmpty();

    $byTopic = $entries->keyBy('notes');
    expect($byTopic->get('Mid-month work')->spent_date->toDateString())->toBe('2026-04-17');
    expect((float) $byTopic->get('Mid-month work')->hours)->toBe(3.0);
    expect($byTopic->get('Late-month work')->spent_date->toDateString())->toBe('2026-04-28');
    expect($byTopic->get('No-date topic falls back')->spent_date->toDateString())->toBe('2026-04-01');
    expect($byTopic->get('Out-of-band date clamps')->spent_date->toDateString())->toBe('2026-04-01');
    expect($byTopic->get('Multi-week feature')->spent_date->toDateString())->toBe('2026-04-20');
    expect((float) $byTopic->get('Multi-week feature')->hours)->toBe(8.0);
});

it('does not recreate an AI entry for a topic already promoted to manual', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);
    $svc = app(\App\Services\Reports\RetainerNarrativeService::class);

    $svc->persistAsTimeEntries($period, [
        ['title' => 'OptinMonster Installation', 'summary' => 'Installed OptinMonster', 'estimated_hours' => 2.5, 'status' => 'completed'],
        ['title' => 'Venue Grid Development', 'summary' => 'New venue grid', 'estimated_hours' => 6.0, 'status' => 'completed'],
    ]);

    // Operator corrects the first entry's hours — promoted to source=manual.
    $corrected = \App\Models\TimeEntry::where('retainer_period_id', $period->id)
        ->where('notes', 'like', 'OptinMonster%')->firstOrFail();
    app(\App\Services\Reports\RetainerTimeEntryAdjuster::class)->adjust($corrected, ['hours' => 4.0]);

    // Regenerate: the LLM re-surfaces the same topic (possibly reworded summary).
    $svc->persistAsTimeEntries($period, [
        ['title' => 'OptinMonster Installation', 'summary' => 'Set up OptinMonster on both sites', 'estimated_hours' => 2.5, 'status' => 'completed'],
        ['title' => 'Venue Grid Development', 'summary' => 'New venue grid', 'estimated_hours' => 6.0, 'status' => 'completed'],
    ]);

    $entries = \App\Models\TimeEntry::where('retainer_period_id', $period->id)->get();
    expect($entries)->toHaveCount(2);

    // The manual correction survives untouched; no ai_estimated twin was added.
    $optin = $entries->filter(fn ($e) => str_starts_with($e->notes, 'OptinMonster'));
    expect($optin)->toHaveCount(1);
    expect($optin->first()->source)->toBe('manual');
    expect((float) $optin->first()->hours)->toBe(4.0);
});

it('report view excludes time entries belonging to an adjacent period', function () {
    $client = Client::factory()->create();
    $july = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);
    $august = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);

    \App\Models\TimeEntry::create([
        'client_id' => $client->id, 'hours' => 1.0, 'source' => 'ai_estimated',
        'retainer_period_id' => $july->id, 'spent_date' => '2026-07-20', 'notes' => 'July topic',
    ]);
    // August's first-day entry: spent_date 08-01 falls inside July's Pacific
    // window (ends 07-31 23:59 PT = 08-01 06:59 UTC) but must not leak in.
    \App\Models\TimeEntry::create([
        'client_id' => $client->id, 'hours' => 0.38, 'source' => 'ai_estimated',
        'retainer_period_id' => $august->id, 'spent_date' => '2026-08-01', 'notes' => 'August topic',
    ]);
    // Untagged tracked time inside July stays visible via the date branch.
    \App\Models\TimeEntry::create([
        'client_id' => $client->id, 'hours' => 2.0, 'source' => 'manual',
        'spent_date' => '2026-07-10', 'notes' => 'Tracked July work',
    ]);

    $data = app(\App\Services\Reports\RetainerReportPdfGenerator::class)->buildViewData($july);

    $notes = $data['timeEntries']->pluck('notes')->all();
    expect($notes)->toContain('July topic');
    expect($notes)->toContain('Tracked July work');
    expect($notes)->not->toContain('August topic');
});

it('regenerating narrative replaces prior AI entries (no duplicates)', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create(['client_id' => $client->id]);
    $svc = app(\App\Services\Reports\RetainerNarrativeService::class);

    $svc->persistAsTimeEntries($period, [
        ['title' => 'Old topic', 'summary' => '', 'estimated_hours' => 5.0, 'status' => 'completed'],
    ]);
    $svc->persistAsTimeEntries($period, [
        ['title' => 'New topic A', 'summary' => '', 'estimated_hours' => 3.0, 'status' => 'completed'],
        ['title' => 'New topic B', 'summary' => '', 'estimated_hours' => 2.0, 'status' => 'in_progress'],
    ]);

    $entries = \App\Models\TimeEntry::where('retainer_period_id', $period->id)->get();
    expect($entries)->toHaveCount(2);
    expect($entries->pluck('notes')->toArray())->toBe(['New topic A', 'New topic B']);
});

it('snapshot reports source=narrative when only AI entries exist (no manual)', function () {
    $client = Client::factory()->create();
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    \App\Models\TimeEntry::create([
        'client_id' => $client->id,
        'hours' => 4.0,
        'source' => 'ai_estimated',
        'retainer_period_id' => $period->id,
        'spent_date' => '2026-04-01',
        'notes' => 'AI topic',
    ]);

    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30'),
        persist: false,
    );

    expect($snapshot['human_hours_source'])->toBe('narrative');
    expect($snapshot['human_hours'])->toBe(4.0);
});

it('timesheet CSV excludes ai_estimated TimeEntries from tracked loop (no double-count)', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['name' => 'Acme Co']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);
    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 2.5,
        'spent_date' => '2026-04-10',
        'notes' => 'Manual entry',
        'source' => 'manual',
    ]);
    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 7.5,
        'spent_date' => '2026-04-01',
        'notes' => 'AI topic — should NOT appear as tracked',
        'source' => 'ai_estimated',
        'retainer_period_id' => $period->id,
    ]);

    $this->mock(\App\Services\Reports\RetainerNarrativeService::class, function ($m) {
        $payload = [
            'topics' => [],
            'value_summary' => 'Summary.',
            'total_estimated_hours' => 7.5,
            'generated_at' => now()->toIso8601String(),
            'warnings' => [],
        ];
        $m->shouldReceive('buildNarrative')->andReturn($payload);
        $m->shouldReceive('getCached')->andReturn($payload);
    });

    $response = $this->actingAs($user)->get("/retainers/{$period->id}/report/csv");
    $response->assertOk();
    $body = $response->streamedContent();

    // Manual entry appears once
    expect(substr_count($body, 'Manual entry'))->toBe(1);
    // AI entry now emitted via the unified time-entries loop with its real
    // spent_date, not via a separate topic loop. Should appear exactly once.
    expect(substr_count($body, 'AI topic'))->toBe(1);
    // Source column intentionally absent — CFO shouldn't query our
    // estimation methodology. Status column distinguishes ('logged' vs 'estimated').
    expect($body)->not->toContain(',Source');
    // Total = 2.5 manual + 7.5 estimated = 10.00
    expect($body)->toContain('10.00');
});

it('timesheet CSV export contains tracked entries and narrative topics with a total', function () {
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['name' => 'Acme Co']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);
    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 2.5,
        'spent_date' => '2026-04-10',
        'notes' => 'Code review',
    ]);

    // AI narrative entries are persisted as TimeEntries with real dates.
    // Seed them directly to mirror what persistAsTimeEntries would produce.
    \App\Models\TimeEntry::factory()->create([
        'client_id' => $client->id,
        'hours' => 7.5,
        'spent_date' => '2026-04-21',
        'notes' => 'Posted-date refresh — Salesforce job stale dates',
        'source' => 'ai_estimated',
        'retainer_period_id' => $period->id,
    ]);

    $this->mock(\App\Services\Reports\RetainerNarrativeService::class, function ($m) {
        $payload = [
            'topics' => [],
            'value_summary' => 'Shipped the posted-date refresh feature.',
            'total_estimated_hours' => 7.5,
            'generated_at' => now()->toIso8601String(),
            'warnings' => [],
        ];
        $m->shouldReceive('buildNarrative')->andReturn($payload);
        $m->shouldReceive('getCached')->andReturn($payload);
    });

    $response = $this->actingAs($user)->get("/retainers/{$period->id}/report/csv");
    $response->assertOk();
    expect(strtolower($response->headers->get('content-type')))->toContain('text/csv');

    $body = $response->streamedContent();
    expect($body)->toContain('Date,Description,Hours');
    expect($body)->not->toContain('Status');
    expect($body)->toContain('Code review');
    expect($body)->toContain('Posted-date refresh');
    expect($body)->toContain('TOTAL');
    // Each AI entry's real date should be in the row. The period-range
    // string still appears as the "# Period" footer comment row — that's
    // expected. Verify the AI row uses the real date by checking the row
    // starts with the YYYY-MM-DD (no "– 2026-04-30" suffix on the same line).
    expect($body)->toContain('2026-04-21');
    expect($body)->toMatch('/^2026-04-21,/m');
    // 2.5 tracked + 7.5 estimated = 10.00
    expect($body)->toContain('10.00');
});

it('admin can view a retainer report without a signed URL', function () {
    $user = \App\Models\User::factory()->create(['role' => 'owner']);
    $period = RetainerPeriod::factory()->create();

    $this->actingAs($user)
        ->get("/retainers/{$period->id}/report")
        ->assertOk()
        ->assertSee('Retainer Report', false);
});

it('does not include period_activity in total_equivalent_hours', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'hours_included' => 10,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // Lots of activity but zero actual hours/meetings/agent-runs.
    GitHubPullRequest::factory()->count(20)->merged()->create([
        'repo_id' => $repo->id,
        'merged_at' => '2026-04-15',
    ]);

    $snapshot = app(RetainerHealthService::class)->computeAndPersistSnapshot(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30'),
        persist: false,
    );

    expect($snapshot['total_equivalent_hours'])->toBe(0.0);
    expect($snapshot['period_activity']['github']['prs_merged'])->toBe(20);
});

it('counts tasks completed inside the period only', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    Task::factory()->completed()->create(['project_id' => $project->id, 'completed_at' => '2026-04-12 09:00:00', 'title' => 'In-period task']);
    Task::factory()->completed()->create(['project_id' => $project->id, 'completed_at' => '2026-03-20 09:00:00', 'title' => 'March task']);
    Task::factory()->completed()->create(['project_id' => $project->id, 'completed_at' => '2026-05-02 09:00:00', 'title' => 'May task']);
    // Completed but never stamped — excluded (no completed_at to attribute).
    Task::factory()->completed()->create(['project_id' => $project->id, 'completed_at' => null, 'title' => 'Unstamped']);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['tasks']['completed_count'])->toBe(1);
    expect($activity['tasks']['completed'])->toHaveCount(1);
    expect($activity['tasks']['completed'][0]['title'])->toBe('In-period task');
});

it('only counts completed tasks from projects linked to the client', function () {
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();
    $projectA = Project::factory()->create(['client_id' => $clientA->id]);
    $projectB = Project::factory()->create(['client_id' => $clientB->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $clientA->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    Task::factory()->completed()->create(['project_id' => $projectA->id, 'completed_at' => '2026-04-10 09:00:00']);
    Task::factory()->completed()->create(['project_id' => $projectB->id, 'completed_at' => '2026-04-10 09:00:00']);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['tasks']['completed_count'])->toBe(1);
});

it('attributes a task to the period by its Pacific date, not UTC', function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    // Closed at 2026-06-01 03:44 UTC — which is 2026-05-31 20:44 Pacific, so it
    // belongs to the May period and should render as May 31, not June 1.
    Task::factory()->completed()->create([
        'project_id' => $project->id,
        'completed_at' => '2026-06-01 03:44:16',
        'title' => 'Late-May close-out',
    ]);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        $period->windowStart(),
        $period->windowEnd(),
    );

    expect($activity['tasks']['completed_count'])->toBe(1);

    $displayDate = \Carbon\Carbon::parse($activity['tasks']['completed'][0]['completed_at'])
        ->setTimezone('America/Los_Angeles')
        ->toDateString();
    expect($displayDate)->toBe('2026-05-31');
});

it('excludes non-completed tasks even when they fall in the period', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    // An in_progress task that somehow has a completed_at must not be counted.
    Task::factory()->inProgress()->create(['project_id' => $project->id, 'completed_at' => '2026-04-15 09:00:00']);

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );

    expect($activity['tasks']['completed_count'])->toBe(0);
});

function slackSlaContext(): array
{
    config()->set('services.slack.internal_user_ids', ['U_TEAM']);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U_BOT']);
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C_SLA1',
        'slack_id' => 'C_SLA1',
    ]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-04-01',
        'period_end' => '2026-04-30',
    ]);

    return compact('client', 'workspace', 'channel', 'period');
}

function slackSlaMessage(array $overrides, string $when): SlackMessage
{
    $at = \Carbon\Carbon::parse($when);

    return SlackMessage::factory()->create(array_merge([
        'user_id' => 'U_CLIENT',
        'user_is_external' => false,
        'thread_ts' => null,
        'content' => 'Need a fix',
        'message_ts' => number_format($at->getTimestamp(), 6, '.', ''),
        'sent_at' => $at,
        'created_at' => $at,
    ], $overrides));
}

function slackSlaActivity($period): array
{
    return app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        \Carbon\Carbon::parse('2026-04-01'),
        \Carbon\Carbon::parse('2026-04-30 23:59:59'),
    );
}

it('reports first-response median, average, and reply coverage for client-originated threads', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $originA = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $originA->message_ts,
    ], '2026-04-10 10:00:00');

    $originB = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-11 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $originB->message_ts,
    ], '2026-04-11 11:00:00');

    $originC = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-12 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $originC->message_ts,
    ], '2026-04-12 15:00:00');

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-13 09:00:00');

    $activity = slackSlaActivity($period);
    $sla = $activity['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(4);
    expect($sla['replied_conversations'])->toBe(3);
    expect($sla['median_seconds'])->toBe(7200.0);
    expect($sla['average_seconds'])->toBe(10800.0);
});

it('ignores internally originated Slack conversations for first-response', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM',
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_CLIENT', 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 09:05:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(0);
    expect($sla['replied_conversations'])->toBe(0);
    expect($sla['median_seconds'])->toBeNull();
});

it('does not treat user_is_external as the client-origin signal', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_is_external' => false,
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'user_is_external' => true, 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 09:30:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['replied_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(1800.0);
});

it('skips bot acks when finding the first Zao reply', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    config()->set('services.slack.internal_user_ids', ['U_TEAM', 'U_BOT']);

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_BOT', 'content' => 'On it', 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 09:01:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 10:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['replied_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(3600.0);
});

it('uses COALESCE(sent_at, to_timestamp(message_ts)) rather than created_at for the SLA clock', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $originTs = (string) \Carbon\Carbon::parse('2026-04-15 09:00:00')->getTimestamp().'.000000';
    $replyTs = (string) \Carbon\Carbon::parse('2026-04-15 12:00:00')->getTimestamp().'.000000';

    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_CLIENT', 'user_is_external' => true,
        'message_ts' => $originTs, 'thread_ts' => null, 'sent_at' => null,
        'created_at' => '2026-03-01 09:00:00',
    ]);
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'message_ts' => $replyTs, 'thread_ts' => $originTs, 'sent_at' => null,
        'created_at' => '2026-03-01 12:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(10800.0);
});

it('keeps top-level messages in different channels as separate conversations', function () {
    ['client' => $client, 'workspace' => $workspace, 'period' => $period] = slackSlaContext();
    $channelA = SlackChannel::factory()->create(['workspace_id' => $workspace->id, 'channel_id' => 'C_A', 'slack_id' => 'C_A']);
    $channelB = SlackChannel::factory()->create(['workspace_id' => $workspace->id, 'channel_id' => 'C_B', 'slack_id' => 'C_B']);

    $ts = number_format(\Carbon\Carbon::parse('2026-04-10 09:00:00')->getTimestamp(), 6, '.', '');
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channelA->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_CLIENT', 'message_ts' => $ts, 'thread_ts' => null,
        'sent_at' => '2026-04-10 09:00:00',
    ]);
    SlackMessage::factory()->create([
        'client_id' => $client->id, 'channel_id' => $channelB->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_CLIENT', 'message_ts' => $ts, 'thread_ts' => null,
        'sent_at' => '2026-04-10 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(2);
    expect($sla['replied_conversations'])->toBe(0);
});

it('still counts a first reply that lands after the period window', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-29 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $origin->message_ts,
    ], '2026-05-02 09:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['replied_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(3 * 86400.0);
});

it('does not attribute an origin outside the period even when the reply is inside it', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-03-30 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $origin->message_ts,
    ], '2026-04-02 09:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(0);
});

it('does not treat an in-period client follow-up as a new origin when the true origin is before the period', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-03-30 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'thread_ts' => $origin->message_ts,
    ], '2026-04-02 09:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(0);
    expect($sla['replied_conversations'])->toBe(0);
    expect($sla['median_seconds'])->toBeNull();
});

it('does not treat an in-period client message as origin when the thread started internally before the period', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM',
    ], '2026-03-30 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'thread_ts' => $origin->message_ts,
    ], '2026-04-02 09:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(0);
});

it('still counts a distinct in-period client thread when a pre-period thread also has an in-period follow-up', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $prior = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-03-30 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'thread_ts' => $prior->message_ts,
    ], '2026-04-02 09:00:00');

    $inPeriod = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $inPeriod->message_ts,
    ], '2026-04-10 10:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['replied_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(3600.0);
});

it('resolves a null sent_at pre-period origin from message_ts instead of treating the in-period follow-up as origin', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $march = \Carbon\Carbon::parse('2026-03-30 09:00:00');
    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'sent_at' => null,
        'message_ts' => number_format($march->getTimestamp(), 6, '.', ''),
    ], '2026-03-30 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'thread_ts' => $origin->message_ts,
    ], '2026-04-02 09:00:00');

    $sla = slackSlaActivity($period)['slack']['first_response'];

    expect($sla['total_conversations'])->toBe(0);
});

it('returns empty Slack SLA when the internal user set is empty', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    config()->set('services.slack.internal_user_ids', []);
    config()->set('services.slack.owner_user_id', null);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_is_external' => true,
    ], '2026-04-10 09:00:00');

    $activity = slackSlaActivity($period);

    expect($activity['slack']['first_response']['total_conversations'])->toBe(0);
    expect($activity['slack']['time_to_merge']['total_conversations'])->toBe(0);
});

it('computes time-to-merge from a Slack-sourced AgentRun PR number', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-04-10 09:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 42,
        'merged_at' => '2026-04-12 09:00:00',
    ]);

    AgentRun::factory()->completed()->create([
        'client_id' => $client->id,
        'invocation_source' => AgentRun::SOURCE_SLACK,
        'context' => [
            'slack' => [
                'channel_id' => $channel->channel_id,
                'thread_ts' => $origin->message_ts,
            ],
        ],
        'output' => ['pr_number' => 42],
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['shipped_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(2 * 86400.0);
});

it('computes time-to-merge from repo-qualified GitHub pull permalinks only', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Please ship https://github.com/acme/acme-app/pull/17 when you can',
    ], '2026-04-10 09:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 17,
        'merged_at' => '2026-04-11 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['shipped_conversations'])->toBe(1);
    expect($sla['median_seconds'])->toBe(86400.0);
});

it('does not treat greedy #N issue mentions as a merged PR', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Can you look at #17?',
    ], '2026-04-10 09:00:00');

    GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'issue_number' => 17,
        'state' => 'closed',
        'closed_at' => '2026-04-11 09:00:00',
    ]);
    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 17,
        'merged_at' => '2026-04-11 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['shipped_conversations'])->toBe(0);
    expect($sla['median_seconds'])->toBeNull();
});

it('ignores pull permalinks that are not on a client repo', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);
    $other = GitHubRepo::factory()->create([
        'full_name' => 'someone/else',
        'name' => 'else',
    ]);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'See https://github.com/someone/else/pull/9',
    ], '2026-04-10 09:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $other->id,
        'pr_number' => 9,
        'merged_at' => '2026-04-11 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['shipped_conversations'])->toBe(0);
});

it('requires the PR merge to happen after the conversation origin', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Following up on https://github.com/acme/acme-app/pull/8',
    ], '2026-04-10 09:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 8,
        'merged_at' => '2026-04-09 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['shipped_conversations'])->toBe(0);
    expect($sla['median_seconds'])->toBeNull();
});

it('keeps unshipped threads in the shipped fraction and out of the merge average', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Please ship https://github.com/acme/acme-app/pull/3',
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Still waiting on a status page tweak',
    ], '2026-04-11 09:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 3,
        'merged_at' => '2026-04-12 09:00:00',
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['total_conversations'])->toBe(2);
    expect($sla['shipped_conversations'])->toBe(1);
    expect($sla['average_seconds'])->toBe(2 * 86400.0);
    expect($sla['median_seconds'])->toBe(2 * 86400.0);
});

it('does not invent a Slack close from last-reply, thanks, or request-pattern resolution', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Thanks so much!',
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'content' => 'You bet — this is resolved.', 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 09:10:00');

    \Illuminate\Support\Facades\DB::table('slack_request_patterns')->insert([
        'client_id' => $client->id,
        'topic_summary' => 'status page',
        'first_asked_at' => '2026-04-10 09:00:00',
        'last_asked_at' => '2026-04-10 09:00:00',
        'ask_count' => 1,
        'is_resolved' => true,
        'resolved_at' => '2026-04-10 09:10:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sla = slackSlaActivity($period)['slack']['time_to_merge'];

    expect($sla['total_conversations'])->toBe(1);
    expect($sla['shipped_conversations'])->toBe(0);
});

it('renders first-response and time-to-merge on the retainer report, labeled merged not deployed', function () {
    ['client' => $client, 'workspace' => $workspace, 'channel' => $channel, 'period' => $period] = slackSlaContext();
    $repo = GitHubRepo::factory()->create([
        'client_id' => $client->id,
        'full_name' => 'acme/acme-app',
        'name' => 'acme-app',
    ]);

    $origin = slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'content' => 'Please merge https://github.com/acme/acme-app/pull/21',
    ], '2026-04-10 09:00:00');
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
        'user_id' => 'U_TEAM', 'thread_ts' => $origin->message_ts,
    ], '2026-04-10 10:00:00');

    GitHubPullRequest::factory()->merged()->create([
        'repo_id' => $repo->id,
        'pr_number' => 21,
        'merged_at' => '2026-04-11 09:00:00',
        'title' => 'Fix login',
    ]);

    $html = app(RetainerReportPdfGenerator::class)->renderHtml($period);

    expect($html)->toContain('First response');
    expect($html)->toContain('Time to merge');
    expect($html)->toContain('threads merged');
    expect($html)->toContain('threads with a reply');
    expect($html)->toContain('1/1 client threads with a reply');
    expect($html)->not->toContain('deployed');
});

it('attributes Slack SLA origins with the period UTC window helpers', function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);
    config()->set('services.slack.internal_user_ids', ['U_TEAM']);
    config()->set('services.slack.owner_user_id', null);

    $client = Client::factory()->create();
    $workspace = SlackWorkspace::factory()->create(['bot_user_id' => 'U_BOT']);
    $channel = SlackChannel::factory()->create(['workspace_id' => $workspace->id]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    // 2026-05-01 03:00 UTC is still 2026-04-30 20:00 Pacific — April, not May.
    slackSlaMessage([
        'client_id' => $client->id, 'channel_id' => $channel->id, 'workspace_id' => $workspace->id,
    ], '2026-05-01 03:00:00');

    $activity = app(RetainerHealthService::class)->aggregatePeriodActivity(
        $period,
        $period->windowStart(),
        $period->windowEnd(),
    );

    expect($activity['slack']['first_response']['total_conversations'])->toBe(0);
});
