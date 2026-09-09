<?php

use App\Models\Client;
use App\Models\Email;
use App\Models\GitHubIssue;
use App\Models\GitHubPullRequest;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackMessage;
use App\Models\Task;
use App\Services\Activity\SignalCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('collects inbound external slack messages within the window', function () {
    $client = Client::factory()->create();

    $inWindow = SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(10),
        'content' => 'Can you check the cache?',
    ]);
    // Outside window — should be excluded
    SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(90),
    ]);
    // Internal (us) message — should be excluded
    SlackMessage::factory()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(5),
        'user_is_external' => false,
    ]);

    $result = app(SignalCollector::class)->collect($client);

    $slack = collect($result['signals'])->where('source_type', 'slack');
    expect($slack)->toHaveCount(1)
        ->and($slack->first()['content'])->toBe('Can you check the cache?')
        ->and($slack->first()['external_id'])->toContain($inWindow->message_ts);
});

it('collects emails from the client domain', function () {
    $client = Client::factory()->create(['billing_email' => 'cory@example-client.com']);

    Email::factory()->create([
        'from_address' => 'cory@example-client.com',
        'received_at' => now()->subDays(5),
        'subject' => 'Vendor registration error',
    ]);
    Email::factory()->create([
        'from_address' => 'someone@elsewhere.com',
        'received_at' => now()->subDays(5),
    ]);

    $result = app(SignalCollector::class)->collect($client);

    $emails = collect($result['signals'])->where('source_type', 'email');
    expect($emails)->toHaveCount(1)
        ->and($emails->first()['content'])->toContain('Vendor registration error');
});

it('collects open github prs and issues from linked repos', function () {
    $client = Client::factory()->create();
    $repo = GitHubRepo::factory()->create(['client_id' => $client->id, 'full_name' => 'zao/test']);

    GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'state' => 'open',
        'pr_number' => 42,
        'title' => 'Add cache invalidation',
    ]);
    GitHubPullRequest::factory()->create([
        'repo_id' => $repo->id,
        'state' => 'closed',
        'pr_number' => 41,
    ]);
    GitHubIssue::factory()->create([
        'repo_id' => $repo->id,
        'state' => 'open',
        'issue_number' => 7,
        'title' => 'Vendor registration bug',
    ]);

    $result = app(SignalCollector::class)->collect($client);

    $prs = collect($result['signals'])->where('source_type', 'github_pr');
    $issues = collect($result['signals'])->where('source_type', 'github_issue');

    expect($prs)->toHaveCount(1)
        ->and($prs->first()['external_id'])->toBe('zao/test:42')
        ->and($issues)->toHaveCount(1)
        ->and($issues->first()['external_id'])->toBe('zao/test:i:7');
});

it("collects open internal tasks for the client's projects", function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);

    $openTask = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'in_progress',
        'title' => 'Fix posted-date',
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'completed',
        'title' => 'Old finished thing',
    ]);

    $result = app(SignalCollector::class)->collect($client);

    $tasks = collect($result['signals'])->where('source_type', 'internal_task');
    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()['external_id'])->toBe('task:'.$openTask->id);
});
