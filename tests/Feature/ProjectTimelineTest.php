<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('accepts start_date and end_date via update', function () {
    $project = Project::factory()->create();

    $this->put("/projects/{$project->id}", [
        'name' => $project->name,
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
    ])
        ->assertRedirect();

    $project->refresh();
    expect($project->start_date->toDateString())->toBe('2026-01-01');
    expect($project->end_date->toDateString())->toBe('2026-06-30');
});

it('validates end_date is after start_date', function () {
    $project = Project::factory()->create();

    $this->put("/projects/{$project->id}", [
        'name' => $project->name,
        'start_date' => '2026-06-30',
        'end_date' => '2026-01-01',
    ])
        ->assertSessionHasErrors('end_date');
});

it('returns timeline data with correct JSON structure', function () {
    $project = Project::factory()->withDates(
        now()->subDays(30)->startOfDay(),
        now()->addDays(60)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(5)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'pending',
    ]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'completed',
    ]);

    $this->getJson("/api/projects/{$project->id}/timeline-data")
        ->assertSuccessful()
        ->assertJsonStructure([
            'project' => ['start_date', 'end_date', 'time_progress_pct', 'task_progress_pct', 'timeline_status', 'days_elapsed', 'days_remaining'],
            'milestones',
            'completion_series',
            'ideal_series',
        ]);
});

it('returns 422 when project has no dates for timeline data', function () {
    $project = Project::factory()->create(['start_date' => null, 'end_date' => null]);

    $this->getJson("/api/projects/{$project->id}/timeline-data")
        ->assertUnprocessable();
});

it('computes timeline status correctly', function () {
    // Create a project that started 50 days ago, ends in 50 days (50% time elapsed)
    $project = Project::factory()->withDates(
        now()->subDays(50)->startOfDay(),
        now()->addDays(50)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    // 80% tasks completed (8/10) with 50% time elapsed = ahead
    Task::factory()->count(8)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'completed',
    ]);
    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'pending',
    ]);

    $this->getJson("/api/projects/{$project->id}/timeline-data")
        ->assertSuccessful()
        ->assertJsonPath('project.timeline_status', 'ahead');
});

it('returns correct milestone completion counts', function () {
    $project = Project::factory()->withDates(
        now()->subDays(30)->startOfDay(),
        now()->addDays(60)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'completed',
    ]);
    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'pending',
    ]);

    $response = $this->getJson("/api/projects/{$project->id}/timeline-data")
        ->assertSuccessful();

    $data = $response->json();
    $milestoneData = collect($data['milestones'])->firstWhere('id', $milestone->id);

    expect($milestoneData['completed_tasks_count'])->toBe(3);
    expect($milestoneData['tasks_count'])->toBe(5);
    expect($milestoneData['progress_pct'])->toBe(60);
});

it('includes today in completion series for recently completed tasks', function () {
    $project = Project::factory()->withDates(
        now()->subDays(14)->startOfDay(),
        now()->addDays(60)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(2)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'status' => 'completed',
        'updated_at' => now(),
    ]);

    $response = $this->getJson("/api/projects/{$project->id}/timeline-data")
        ->assertSuccessful();

    $series = $response->json('completion_series');
    $lastPoint = end($series);

    // The last point should reflect today's completed count
    expect($lastPoint['completed'])->toBe(2);
    expect($lastPoint['date'])->toBe(now()->toDateString());
});

it('distributes dates via HTTP endpoint', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $this->post("/projects/{$project->id}/distribute-dates", [
        'overwrite_existing' => false,
    ])
        ->assertRedirect();

    // All tasks should now have due dates
    $tasks = $project->tasks()->get();
    foreach ($tasks as $task) {
        expect($task->due_date)->not->toBeNull();
    }
});

it('previews distribution without persisting', function () {
    $project = Project::factory()->withDates(
        now()->startOfDay(),
        now()->addDays(30)->startOfDay()
    )->create();

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    Task::factory()->count(3)->create([
        'project_id' => $project->id,
        'milestone_id' => $milestone->id,
        'due_date' => null,
    ]);

    $this->getJson("/api/projects/{$project->id}/preview-distribution")
        ->assertSuccessful()
        ->assertJsonStructure([
            'milestones',
            'summary' => ['milestones_updated', 'tasks_updated'],
        ]);

    // Tasks should still have null dates
    $tasks = $project->tasks()->get();
    foreach ($tasks as $task) {
        expect($task->due_date)->toBeNull();
    }
});
