<?php

use App\Models\GitHubIssue;
use App\Models\GitHubRepo;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new GitHubIssue)->getGuarded())->toBe([]);
});

test('casts labels to array', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['bug', 'enhancement'],
    ]);

    expect($issue->labels)->toBeArray()
        ->and($issue->labels)->toBe(['bug', 'enhancement']);
});

test('casts assignees to array', function () {
    $issue = GitHubIssue::factory()->create([
        'assignees' => ['user1', 'user2'],
    ]);

    expect($issue->assignees)->toBeArray()
        ->and($issue->assignees)->toBe(['user1', 'user2']);
});

test('casts closed_at to datetime', function () {
    $issue = GitHubIssue::factory()->create([
        'closed_at' => now(),
    ]);

    expect($issue->closed_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('belongs to repo relationship', function () {
    $issue = GitHubIssue::factory()->create();

    expect($issue->repo())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to task relationship', function () {
    $issue = GitHubIssue::factory()->create();

    expect($issue->task())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to agentRun relationship', function () {
    $issue = GitHubIssue::factory()->create();

    expect($issue->agentRun())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isOpen returns true when state is open', function () {
    $issue = GitHubIssue::factory()->create(['state' => 'open']);

    expect($issue->isOpen())->toBeTrue();
});

test('isOpen returns false when state is closed', function () {
    $issue = GitHubIssue::factory()->create(['state' => 'closed']);

    expect($issue->isOpen())->toBeFalse();
});

test('hasLabel returns true when label exists case insensitive', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['Bug', 'Enhancement'],
    ]);

    expect($issue->hasLabel('bug'))->toBeTrue()
        ->and($issue->hasLabel('enhancement'))->toBeTrue()
        ->and($issue->hasLabel('BUG'))->toBeTrue();
});

test('hasLabel returns false when label does not exist', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['bug'],
    ]);

    expect($issue->hasLabel('feature'))->toBeFalse();
});

test('hasLabel handles null labels', function () {
    $issue = GitHubIssue::factory()->create(['labels' => null]);

    expect($issue->hasLabel('bug'))->toBeFalse();
});

test('isAgentTask returns true when has agent label', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['agent', 'bug'],
    ]);

    expect($issue->isAgentTask())->toBeTrue();
});

test('isAgentTask returns true when has agent-task label', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['agent-task'],
    ]);

    expect($issue->isAgentTask())->toBeTrue();
});

test('isAgentTask returns false when no agent labels', function () {
    $issue = GitHubIssue::factory()->create([
        'labels' => ['bug', 'enhancement'],
    ]);

    expect($issue->isAgentTask())->toBeFalse();
});

test('getUrlAttribute returns correct GitHub URL', function () {
    $repo = GitHubRepo::factory()->create(['full_name' => 'owner/repo']);
    $issue = GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'issue_number' => 123,
    ]);

    expect($issue->url)->toBe('https://github.com/owner/repo/issues/123');
});

test('can be created via factory', function () {
    $issue = GitHubIssue::factory()->create();

    expect($issue)->toBeInstanceOf(GitHubIssue::class)
        ->and($issue->exists)->toBeTrue();
});
