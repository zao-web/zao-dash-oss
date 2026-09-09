<?php

use App\Jobs\HandleProposalSlackRevisionJob;
use App\Models\RfpProposal;
use App\Services\AI\ClaudeCliService;
use App\Services\Rfp\RfpSlackNotifier;
use App\Services\Slack\SlackApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Job queue ────────────────────────────────────────────────────────────────

it('is dispatched on the slack-mentions queue', function () {
    $job = new HandleProposalSlackRevisionJob(1, 'Make it shorter', '1234567890.123456', 'D0123ABCDE');

    expect($job->queue)->toBe('slack-mentions');
});

// ─── Successful revision ──────────────────────────────────────────────────────

it('applies executive summary revision from Claude and replies in thread', function () {
    $proposal = RfpProposal::factory()->create([
        'executive_summary' => 'Original summary.',
        'full_content' => 'Original full content.',
        'proposal_sections' => [
            ['title' => 'Approach', 'content' => 'Original approach.'],
        ],
    ]);

    $claudeResponse = json_encode([
        'executive_summary' => 'Revised summary — more concise and punchy.',
        'summary_of_changes' => 'Rewrote the executive summary for brevity.',
    ]);

    $claude = mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn(['content' => $claudeResponse]);

    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')->twice(); // ack + done reply

    $job = new HandleProposalSlackRevisionJob(
        $proposal->id,
        'Make the executive summary more concise',
        '1234567890.123456',
        'D0123ABCDE',
    );
    $job->handle($claude, $slack);

    $proposal->refresh();
    expect($proposal->executive_summary)->toBe('Revised summary — more concise and punchy.');
    expect($proposal->full_content)->toBe('Original full content.'); // unchanged
});

// ─── Section-level revision ───────────────────────────────────────────────────

it('updates a named section without touching other sections', function () {
    $proposal = RfpProposal::factory()->create([
        'proposal_sections' => [
            ['title' => 'Approach', 'content' => 'Old approach.'],
            ['title' => 'Timeline', 'content' => 'Old timeline.'],
        ],
    ]);

    $claudeResponse = json_encode([
        'sections' => [
            ['title' => 'Approach', 'content' => 'New approach emphasising AI tooling.'],
        ],
        'summary_of_changes' => 'Updated Approach section.',
    ]);

    $claude = mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn(['content' => $claudeResponse]);

    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')->twice();

    $job = new HandleProposalSlackRevisionJob($proposal->id, 'Rewrite approach', '111.222', 'D111');
    $job->handle($claude, $slack);

    $proposal->refresh();
    $approach = collect($proposal->proposal_sections)->firstWhere('title', 'Approach');
    $timeline = collect($proposal->proposal_sections)->firstWhere('title', 'Timeline');

    expect($approach['content'])->toBe('New approach emphasising AI tooling.');
    expect($timeline['content'])->toBe('Old timeline.'); // untouched
});

// ─── PDF cache cleared on content change ─────────────────────────────────────

it('clears pdf_path when full_content is updated', function () {
    $proposal = RfpProposal::factory()->create([
        'full_content' => 'Old content.',
        'pdf_path' => 'proposals/cached.pdf',
    ]);

    $claudeResponse = json_encode([
        'full_content' => 'Entirely new content.',
        'summary_of_changes' => 'Rewrote full proposal.',
    ]);

    $claude = mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn(['content' => $claudeResponse]);

    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')->twice();

    $job = new HandleProposalSlackRevisionJob($proposal->id, 'Rewrite everything', '111.222', 'D111');
    $job->handle($claude, $slack);

    $proposal->refresh();
    expect($proposal->pdf_path)->toBeNull();
});

// ─── Missing proposal ─────────────────────────────────────────────────────────

it('exits gracefully when proposal is not found', function () {
    $claude = mock(ClaudeCliService::class);
    $claude->shouldNotReceive('message');

    $slack = mock(SlackApiService::class);
    $slack->shouldNotReceive('postMessageDirect');

    $job = new HandleProposalSlackRevisionJob(99999, 'revise', '111.222', 'D111');

    expect(fn () => $job->handle($claude, $slack))->not->toThrow(\Throwable::class);
});

// ─── Claude error → thread reply ──────────────────────────────────────────────

it('replies with an error message in thread when Claude throws', function () {
    $proposal = RfpProposal::factory()->create();

    $claude = mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andThrow(new \RuntimeException('API timeout'));

    $errorReplied = false;
    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')->andReturnUsing(function ($ch, $text) use (&$errorReplied) {
        if (str_contains($text, ':x:')) {
            $errorReplied = true;
        }

        return [];
    });

    $job = new HandleProposalSlackRevisionJob($proposal->id, 'do something', '111.222', 'D111');
    $job->handle($claude, $slack);

    expect($errorReplied)->toBeTrue();
});

// ─── Slack notification ts stored on proposal ─────────────────────────────────

it('stores slack_notification_ts and channel_id on proposal when notifyProposalReady succeeds', function () {
    config(['services.slack.owner_user_id' => 'U123TEST']);

    $proposal = RfpProposal::factory()->create([
        'slack_notification_ts' => null,
        'slack_channel_id' => null,
    ]);
    $rfp = $proposal->opportunity;

    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')
        ->once()
        ->andReturn(['ts' => '1746000000.123456', 'channel' => 'D0ABCTEST']);

    $notifier = new RfpSlackNotifier($slack);
    $notifier->notifyProposalReady($rfp, $proposal);

    $proposal->refresh();
    expect($proposal->slack_notification_ts)->toBe('1746000000.123456');
    expect($proposal->slack_channel_id)->toBe('D0ABCTEST');
});

// ─── No ts stored when Slack call fails ───────────────────────────────────────

// ─── URL format uses id not slug (regression guard) ──────────────────────────

it('builds proposal review URL using rfp id not slug', function () {
    config(['services.slack.owner_user_id' => 'U123TEST']);

    $proposal = RfpProposal::factory()->create();
    $rfp = $proposal->opportunity;

    $capturedArgs = [];
    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')
        ->once()
        ->andReturnUsing(function () use (&$capturedArgs) {
            $capturedArgs = func_get_args();

            return ['ts' => '111.222', 'channel' => 'D111'];
        });

    $notifier = new RfpSlackNotifier($slack);
    $notifier->notifyProposalReady($rfp, $proposal);

    // Blocks (arg index 3) must use numeric id — slug causes PostgreSQL bigint cast error (500)
    $blocksJson = json_encode($capturedArgs[3] ?? [], JSON_UNESCAPED_SLASHES);
    expect($blocksJson)->toContain("/rfp/{$rfp->id}/proposals/{$proposal->id}/review");
    expect($blocksJson)->not->toContain("/rfp/{$rfp->slug}/");
});

it('revision job reply URL uses rfp id not slug', function () {
    $proposal = RfpProposal::factory()->create();

    $claude = mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn([
        'content' => json_encode([
            'executive_summary' => 'Updated summary.',
            'summary_of_changes' => 'Rewrote summary.',
        ]),
    ]);

    $capturedReplies = [];
    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')->andReturnUsing(function ($ch, $text) use (&$capturedReplies) {
        $capturedReplies[] = $text;

        return [];
    });

    $job = new HandleProposalSlackRevisionJob($proposal->id, 'rewrite summary', '111.222', 'D111');
    $job->handle($claude, $slack);

    $rfp = $proposal->opportunity;
    $doneReply = collect($capturedReplies)->first(fn ($t) => str_contains($t, 'white_check_mark'));
    expect($doneReply)->toContain("/rfp/{$rfp->id}/proposals/{$proposal->id}/review");
    expect($doneReply)->not->toContain("/rfp/{$rfp->slug}/");
});

// ─── No ts stored when Slack call fails ───────────────────────────────────────

it('does not crash when Slack DM fails and leaves slack_notification_ts null', function () {
    config(['services.slack.owner_user_id' => 'U123TEST']);

    $proposal = RfpProposal::factory()->create([
        'slack_notification_ts' => null,
    ]);
    $rfp = $proposal->opportunity;

    $slack = mock(SlackApiService::class);
    $slack->shouldReceive('postMessageDirect')
        ->once()
        ->andThrow(new \Exception('No workspace'));

    $notifier = new RfpSlackNotifier($slack);

    expect(fn () => $notifier->notifyProposalReady($rfp, $proposal))->not->toThrow(\Throwable::class);

    $proposal->refresh();
    expect($proposal->slack_notification_ts)->toBeNull();
});
