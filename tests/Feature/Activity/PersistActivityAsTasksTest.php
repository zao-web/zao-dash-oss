<?php

use App\Models\Client;
use App\Models\ExternalTaskMapping;
use App\Models\Project;
use App\Models\Task;
use App\Services\Activity\PersistActivityAsTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeItem(array $overrides = []): array
{
    return array_merge([
        'title' => 'Cache invalidation on inventory pages',
        'client_summary' => "You asked about stale cache headers on May 12; we've shipped a fix.",
        'status' => 'in_progress',
        'first_raised_at' => '2026-05-12',
        'last_activity_at' => '2026-05-16',
        'evidence' => ['Slack: cache headers May 12'],
        'external_ids' => [
            ['source' => 'slack', 'id' => 'C0123:1715000000.123456'],
        ],
    ], $overrides);
}

it('creates a task on first persist', function () {
    $client = Client::factory()->create();

    $stats = app(PersistActivityAsTasks::class)->persist($client, [makeItem()]);

    expect($stats['created'])->toBe(1)
        ->and($stats['updated'])->toBe(0)
        ->and(Task::where('source', 'activity-feed')->count())->toBe(1);

    $task = Task::where('source', 'activity-feed')->first();
    expect($task->title)->toBe('Cache invalidation on inventory pages')
        ->and($task->status)->toBe('in_progress');

    // Mapping exists for dedup on next run
    $mapping = ExternalTaskMapping::first();
    expect($mapping->external_id)->toBe('C0123:1715000000.123456')
        ->and($mapping->external_data['client_facing_status'])->toBe('in_progress');
});

it('updates the same task on second persist instead of creating a duplicate', function () {
    $client = Client::factory()->create();
    $service = app(PersistActivityAsTasks::class);

    $service->persist($client, [makeItem()]);
    $stats = $service->persist($client, [makeItem(['status' => 'completed_recently', 'title' => 'Cache fix shipped'])]);

    expect($stats['created'])->toBe(0)
        ->and($stats['updated'])->toBe(1)
        ->and(Task::where('source', 'activity-feed')->count())->toBe(1);

    $task = Task::first();
    expect($task->title)->toBe('Cache fix shipped')
        ->and($task->status)->toBe('completed');
});

it('skips updating a task that was manually edited since last sync', function () {
    $client = Client::factory()->create();
    $service = app(PersistActivityAsTasks::class);

    $service->persist($client, [makeItem()]);

    // Simulate manual edit AFTER last_synced_at by bumping updated_at
    $task = Task::first();
    $task->title = 'Manually renamed';
    $task->save();
    $task->touch();
    $task->update(['updated_at' => now()->addHour()]);

    $stats = $service->persist($client, [makeItem(['title' => 'LLM wants to overwrite'])]);

    expect($stats['skipped_conflict'])->toBe(1)
        ->and(Task::first()->title)->toBe('Manually renamed');
});

it('routes slack-only items to the channel-linked project when one exists', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $workspace = \App\Models\SlackWorkspace::factory()->create();
    $channel = \App\Models\SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'C0123ABC',
    ]);
    $project->update(['slack_channel_id' => $channel->id]);

    app(PersistActivityAsTasks::class)->persist($client, [makeItem([
        'external_ids' => [
            ['source' => 'slack', 'id' => 'C0123ABC:1715000000.123456'],
        ],
    ])]);

    $task = Task::where('source', 'activity-feed')->first();
    expect($task->project_id)->toBe($project->id);
});

it('falls back to the client primary project when no signal-level routing succeeds', function () {
    $client = Client::factory()->create();
    Project::factory()->create(['client_id' => $client->id, 'status' => 'completed', 'name' => 'Old']);
    $active = Project::factory()->create(['client_id' => $client->id, 'status' => 'active', 'name' => 'Current']);

    app(PersistActivityAsTasks::class)->persist($client, [makeItem([
        'external_ids' => [
            ['source' => 'slack', 'id' => 'UNLINKED:1715000000.123456'],
        ],
    ])]);

    $task = Task::where('source', 'activity-feed')->first();
    expect($task->project_id)->toBe($active->id);
});

it('retroactively backfills project_id on subsequent runs', function () {
    $client = Client::factory()->create();
    $service = app(PersistActivityAsTasks::class);

    // First run — no project exists yet, task lands without project_id
    $service->persist($client, [makeItem()]);
    expect(Task::first()->project_id)->toBeNull();

    // Project added; second run should retroactively assign
    $project = Project::factory()->create(['client_id' => $client->id, 'status' => 'active']);
    $service->persist($client, [makeItem(['title' => 'Same item, second run'])]);

    expect(Task::first()->project_id)->toBe($project->id);
});

it('links to existing internal task when external_id points at one', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $existing = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
        'title' => 'Original manual task',
        'source' => 'manual',
    ]);

    app(PersistActivityAsTasks::class)->persist($client, [makeItem([
        'external_ids' => [
            ['source' => 'internal_task', 'id' => 'task:'.$existing->id],
            ['source' => 'slack', 'id' => 'C0123:1715000000.999'],
        ],
    ])]);

    // No new task created — the existing one is augmented with mappings
    expect(Task::count())->toBe(1);

    $existing->refresh();
    expect($existing->externalMappings()->count())->toBe(2);
});
