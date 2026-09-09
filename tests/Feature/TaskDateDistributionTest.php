<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskDateDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('distributes dates evenly when no estimated hours', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'estimated_hours' => null,
        'due_date' => null,
    ]);

    $service = new TaskDateDistributionService;
    $result = $service->distribute($project, true);

    expect($result['tasks_updated'])->toBe(3);
    expect($result['milestones_updated'])->toBe(1);

    // All tasks should now have due dates
    $tasks = $project->tasks()->orderBy('id')->get();
    foreach ($tasks as $task) {
        expect($task->due_date)->not->toBeNull();
    }
});

it('weights milestone windows by estimated hours', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(99)->startOfDay()
    )->create();

    $m1 = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Small']);
    $m2 = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Large']);

    // Small milestone: 10 hours total
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m1->id, 'estimated_hours' => 10, 'due_date' => null]);

    // Large milestone: 90 hours total
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $m2->id, 'estimated_hours' => 90, 'due_date' => null]);

    $service = new TaskDateDistributionService;
    $plan = $service->preview($project);

    // The large milestone should get ~90% of the timeline
    $m1Plan = collect($plan['milestones'])->firstWhere('id', $m1->id);
    $m2Plan = collect($plan['milestones'])->firstWhere('id', $m2->id);

    $m1Days = \Carbon\Carbon::parse($m1Plan['window_start'])->diffInDays(\Carbon\Carbon::parse($m1Plan['window_end']));
    $m2Days = \Carbon\Carbon::parse($m2Plan['window_start'])->diffInDays(\Carbon\Carbon::parse($m2Plan['window_end']));

    expect($m2Days)->toBeGreaterThan($m1Days);
});

it('places unassigned tasks at end', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => null]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => null, 'due_date' => null]);

    $service = new TaskDateDistributionService;
    $plan = $service->preview($project);

    $lastBucket = collect($plan['milestones'])->last();
    expect($lastBucket['name'])->toBe('Unassigned');
});

it('respects overwrite_existing false by skipping tasks with existing dates', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    $existingDate = now()->addDays(5)->startOfDay();
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => $existingDate,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $service = new TaskDateDistributionService;
    $result = $service->distribute($project, false);

    expect($result['tasks_updated'])->toBe(1);

    // The existing date should be unchanged
    $tasks = $project->tasks()->orderBy('id')->get();
    expect($tasks[0]->due_date->toDateString())->toBe($existingDate->toDateString());
});

it('respects overwrite_existing true by redistributing all tasks', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    $existingDate = now()->addDays(5)->startOfDay();
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => $existingDate,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $service = new TaskDateDistributionService;
    $result = $service->distribute($project, true);

    expect($result['tasks_updated'])->toBeGreaterThanOrEqual(1);
});

it('throws when project has no dates', function () {
    $project = Project::factory()->create(['start_date' => null, 'end_date' => null]);

    $service = new TaskDateDistributionService;
    $service->distribute($project);
})->throws(\InvalidArgumentException::class);

it('updates milestone due_dates to window boundaries', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'due_date' => null]);
    Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => null]);

    $service = new TaskDateDistributionService;
    $service->distribute($project);

    $milestone->refresh();
    expect($milestone->due_date)->not->toBeNull();
});

it('handles project with no tasks gracefully', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $service = new TaskDateDistributionService;
    $result = $service->distribute($project);

    expect($result['tasks_updated'])->toBe(0);
    expect($result['milestones_updated'])->toBe(0);
});

it('handles single-day date range', function () {
    $date = now()->startOfDay();
    $project = Project::factory()->withDates($date, $date)->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $service = new TaskDateDistributionService;
    $result = $service->distribute($project, true);

    // All tasks should get the same date
    $tasks = $project->tasks()->get();
    foreach ($tasks as $task) {
        expect($task->due_date->toDateString())->toBe($date->toDateString());
    }
});

it('clamps all dates within project range', function () {
    $start = now()->startOfDay();
    $end = now()->addDays(30)->startOfDay();
    $project = Project::factory()->withDates($start, $end)->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(5)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $service = new TaskDateDistributionService;
    $service->distribute($project, true);

    $tasks = $project->tasks()->get();
    foreach ($tasks as $task) {
        expect($task->due_date->gte($start))->toBeTrue();
        expect($task->due_date->lte($end))->toBeTrue();
    }
});

it('anchors undated tasks around existing dated tasks', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    // Create tasks in order: undated, dated (anchor at day 15), undated
    $t1 = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => null]);
    $anchor = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => now()->addDays(15)->startOfDay()]);
    $t3 = Task::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id, 'due_date' => null]);

    $service = new TaskDateDistributionService;
    $service->distribute($project, false);

    $t1->refresh();
    $anchor->refresh();
    $t3->refresh();

    // Anchor date should be unchanged
    expect($anchor->due_date->toDateString())->toBe(now()->addDays(15)->startOfDay()->toDateString());

    // t1 should be before the anchor, t3 should be after
    expect($t1->due_date)->not->toBeNull();
    expect($t3->due_date)->not->toBeNull();
    expect($t1->due_date->lte($anchor->due_date))->toBeTrue();
    expect($t3->due_date->gte($anchor->due_date))->toBeTrue();
});

it('distributes proportionally by estimated hours within milestones', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(100)->startOfDay()
    )->create();

    $small = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Small']);
    $large = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Large']);

    // Small: 20h, Large: 80h
    Task::factory()->count(2)->create(['project_id' => $project->id, 'milestone_id' => $small->id, 'estimated_hours' => 10, 'due_date' => null]);
    Task::factory()->count(4)->create(['project_id' => $project->id, 'milestone_id' => $large->id, 'estimated_hours' => 20, 'due_date' => null]);

    $service = new TaskDateDistributionService;
    $plan = $service->preview($project);

    $smallPlan = collect($plan['milestones'])->firstWhere('id', $small->id);
    $largePlan = collect($plan['milestones'])->firstWhere('id', $large->id);

    $smallDays = \Carbon\Carbon::parse($smallPlan['window_start'])->diffInDays(\Carbon\Carbon::parse($smallPlan['window_end']));
    $largeDays = \Carbon\Carbon::parse($largePlan['window_start'])->diffInDays(\Carbon\Carbon::parse($largePlan['window_end']));

    // Large milestone (80h) should get ~4x the days of small (20h)
    expect($largeDays)->toBeGreaterThan($smallDays * 2);
});
