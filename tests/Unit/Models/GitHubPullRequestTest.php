<?php

use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new GitHubPullRequest)->getGuarded())->toBe([]);
});

test('casts reviewers to array', function () {
    $pr = GitHubPullRequest::factory()->create([
        'reviewers' => ['user1', 'user2'],
    ]);

    expect($pr->reviewers)->toBeArray()
        ->and($pr->reviewers)->toBe(['user1', 'user2']);
});

test('casts checks_passed to boolean', function () {
    $pr = GitHubPullRequest::factory()->create(['checks_passed' => true]);

    expect($pr->checks_passed)->toBeTrue();
});

test('casts merged_at to datetime', function () {
    $pr = GitHubPullRequest::factory()->create([
        'merged_at' => now(),
    ]);

    expect($pr->merged_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('belongs to repo relationship', function () {
    $pr = GitHubPullRequest::factory()->create();

    expect($pr->repo())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to approvalRequest relationship', function () {
    $pr = GitHubPullRequest::factory()->create();

    expect($pr->approvalRequest())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to qaAgentRun relationship', function () {
    $pr = GitHubPullRequest::factory()->create();

    expect($pr->qaAgentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isOpen returns true when state is open', function () {
    $pr = GitHubPullRequest::factory()->create(['state' => 'open']);

    expect($pr->isOpen())->toBeTrue();
});

test('isOpen returns false when state is closed', function () {
    $pr = GitHubPullRequest::factory()->create(['state' => 'closed']);

    expect($pr->isOpen())->toBeFalse();
});

test('isMerged returns true when state is merged', function () {
    $pr = GitHubPullRequest::factory()->create(['state' => 'merged']);

    expect($pr->isMerged())->toBeTrue();
});

test('isMerged returns true when merged_at is set', function () {
    $pr = GitHubPullRequest::factory()->create([
        'state' => 'closed',
        'merged_at' => now(),
    ]);

    expect($pr->isMerged())->toBeTrue();
});

test('isMerged returns false when not merged', function () {
    $pr = GitHubPullRequest::factory()->create([
        'state' => 'open',
        'merged_at' => null,
    ]);

    expect($pr->isMerged())->toBeFalse();
});

test('targetsMain returns true for main branch', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'main']);

    expect($pr->targetsMain())->toBeTrue();
});

test('targetsMain returns true for master branch', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'master']);

    expect($pr->targetsMain())->toBeTrue();
});

test('targetsMain returns false for other branches', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'develop']);

    expect($pr->targetsMain())->toBeFalse();
});

test('targetsDevelop returns true for develop branch', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'develop']);

    expect($pr->targetsDevelop())->toBeTrue();
});

test('targetsDevelop returns true for dev branch', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'dev']);

    expect($pr->targetsDevelop())->toBeTrue();
});

test('targetsDevelop returns true for development branch', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'development']);

    expect($pr->targetsDevelop())->toBeTrue();
});

test('targetsDevelop returns false for other branches', function () {
    $pr = GitHubPullRequest::factory()->create(['base_branch' => 'main']);

    expect($pr->targetsDevelop())->toBeFalse();
});

test('needsApproval returns true when targeting main and pending', function () {
    $pr = GitHubPullRequest::factory()->create([
        'base_branch' => 'main',
        'approval_status' => 'pending',
    ]);

    expect($pr->needsApproval())->toBeTrue();
});

test('needsApproval returns false when not targeting main', function () {
    $pr = GitHubPullRequest::factory()->create([
        'base_branch' => 'develop',
        'approval_status' => 'pending',
    ]);

    expect($pr->needsApproval())->toBeFalse();
});

test('needsApproval returns false when not pending', function () {
    $pr = GitHubPullRequest::factory()->create([
        'base_branch' => 'main',
        'approval_status' => 'approved',
    ]);

    expect($pr->needsApproval())->toBeFalse();
});

test('getUrlAttribute returns correct GitHub URL', function () {
    $repo = GitHubRepo::factory()->create(['full_name' => 'owner/repo']);
    $pr = GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'pr_number' => 456,
    ]);

    expect($pr->url)->toBe('https://github.com/owner/repo/pull/456');
});

test('can be created via factory', function () {
    $pr = GitHubPullRequest::factory()->create();

    expect($pr)->toBeInstanceOf(GitHubPullRequest::class)
        ->and($pr->exists)->toBeTrue();
});
