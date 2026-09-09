<?php

namespace App\Services;

use App\Models\Project;
use Carbon\Carbon;

class TaskDateDistributionService
{
    /**
     * Distribute due dates across a project's milestones and tasks.
     *
     * @return array{milestones_updated: int, tasks_updated: int}
     */
    public function distribute(Project $project, bool $overwriteExisting = false): array
    {
        if (! $project->start_date || ! $project->end_date) {
            throw new \InvalidArgumentException('Project must have start_date and end_date set.');
        }

        $plan = $this->computePlan($project, $overwriteExisting);

        $milestonesUpdated = 0;
        $tasksUpdated = 0;

        foreach ($plan['milestones'] as $milestonePlan) {
            if ($milestonePlan['id'] !== null) {
                $milestone = $project->milestones()->find($milestonePlan['id']);
                if ($milestone) {
                    $milestone->update(['due_date' => $milestonePlan['window_end']]);
                    $milestonesUpdated++;
                }
            }

            foreach ($milestonePlan['tasks'] as $taskPlan) {
                if ($taskPlan['new_date'] !== null && $taskPlan['new_date'] !== $taskPlan['current_date']) {
                    \App\Models\Task::where('id', $taskPlan['id'])->update(['due_date' => $taskPlan['new_date']]);
                    $tasksUpdated++;
                }
            }
        }

        return [
            'milestones_updated' => $milestonesUpdated,
            'tasks_updated' => $tasksUpdated,
        ];
    }

    /**
     * Preview date distribution without persisting changes.
     *
     * @return array{milestones: array, summary: array}
     */
    public function preview(Project $project, bool $overwriteExisting = false): array
    {
        if (! $project->start_date || ! $project->end_date) {
            throw new \InvalidArgumentException('Project must have start_date and end_date set.');
        }

        return $this->computePlan($project, $overwriteExisting);
    }

    /**
     * Compute the date distribution plan.
     */
    protected function computePlan(Project $project, bool $overwriteExisting): array
    {
        $startDate = $project->start_date->copy()->startOfDay();
        $endDate = $project->end_date->copy()->startOfDay();
        $totalDays = max(1, $startDate->diffInDays($endDate));

        // Load milestones ordered by id (creation order = intended sequence)
        $milestones = $project->milestones()->orderBy('id')->get();
        $allTasks = $project->tasks()->orderBy('position')->orderBy('id')->get();

        // Group tasks by milestone, null milestone goes last as "Unassigned"
        $buckets = [];
        foreach ($milestones as $milestone) {
            $buckets[] = [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'tasks' => $allTasks->where('milestone_id', $milestone->id)->values(),
            ];
        }

        // Unassigned tasks bucket
        $unassigned = $allTasks->whereNull('milestone_id')->values();
        if ($unassigned->isNotEmpty()) {
            $buckets[] = [
                'id' => null,
                'name' => 'Unassigned',
                'tasks' => $unassigned,
            ];
        }

        if (empty($buckets)) {
            return ['milestones' => [], 'summary' => ['milestones_updated' => 0, 'tasks_updated' => 0]];
        }

        // Calculate weights for each bucket
        $weights = [];
        $hasEstimates = $allTasks->whereNotNull('estimated_hours')->where('estimated_hours', '>', 0)->isNotEmpty();

        foreach ($buckets as $bucket) {
            if ($hasEstimates) {
                $weight = $bucket['tasks']->sum('estimated_hours');
                // Fallback: if this bucket has no estimates, use task count
                if ($weight <= 0) {
                    $weight = $bucket['tasks']->count();
                }
            } else {
                $weight = max(1, $bucket['tasks']->count());
            }
            $weights[] = max(1, $weight);
        }

        $totalWeight = array_sum($weights);

        // Divide date range proportionally
        $milestonePlans = [];
        $currentStart = $startDate->copy();

        foreach ($buckets as $i => $bucket) {
            $proportion = $weights[$i] / $totalWeight;
            $windowDays = max(1, (int) round($totalDays * $proportion));

            // Last bucket gets remaining days to avoid rounding issues
            if ($i === count($buckets) - 1) {
                $windowEnd = $endDate->copy();
            } else {
                $windowEnd = $currentStart->copy()->addDays($windowDays - 1);
                // Clamp to project end date
                if ($windowEnd->gt($endDate)) {
                    $windowEnd = $endDate->copy();
                }
            }

            $taskPlans = $this->distributeTasksInWindow(
                $bucket['tasks'],
                $currentStart->copy(),
                $windowEnd->copy(),
                $overwriteExisting
            );

            $milestonePlans[] = [
                'id' => $bucket['id'],
                'name' => $bucket['name'],
                'window_start' => $currentStart->toDateString(),
                'window_end' => $windowEnd->toDateString(),
                'tasks' => $taskPlans,
            ];

            $currentStart = $windowEnd->copy()->addDay();
        }

        $totalTasksUpdated = collect($milestonePlans)->sum(fn ($m) => collect($m['tasks'])->filter(fn ($t) => $t['new_date'] !== null && $t['new_date'] !== $t['current_date'])->count());

        return [
            'milestones' => $milestonePlans,
            'summary' => [
                'milestones_updated' => collect($milestonePlans)->filter(fn ($m) => $m['id'] !== null)->count(),
                'tasks_updated' => $totalTasksUpdated,
            ],
        ];
    }

    /**
     * Distribute tasks within a milestone's date window, respecting anchor dates.
     */
    protected function distributeTasksInWindow(
        \Illuminate\Support\Collection $tasks,
        Carbon $windowStart,
        Carbon $windowEnd,
        bool $overwriteExisting
    ): array {
        if ($tasks->isEmpty()) {
            return [];
        }

        $taskList = $tasks->values()->all();

        if ($overwriteExisting) {
            // Distribute all tasks evenly across the window
            return $this->distributeEvenly($taskList, $windowStart, $windowEnd);
        }

        // Anchor-aware distribution
        // Identify anchors (tasks with existing due_date within window)
        $anchors = [];
        $unanchored = [];

        foreach ($taskList as $index => $task) {
            if ($task->due_date) {
                $anchors[] = ['index' => $index, 'task' => $task, 'date' => $task->due_date->copy()];
            } else {
                $unanchored[] = ['index' => $index, 'task' => $task];
            }
        }

        if (empty($unanchored)) {
            // All tasks have dates, nothing to distribute
            return array_map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'milestone_name' => $task->milestone?->name ?? 'Unassigned',
                'current_date' => $task->due_date?->toDateString(),
                'new_date' => $task->due_date?->toDateString(),
            ], $taskList);
        }

        if (empty($anchors)) {
            // No anchors, distribute evenly
            return $this->distributeEvenly($taskList, $windowStart, $windowEnd);
        }

        // Sort anchors by their position in the task list
        usort($anchors, fn ($a, $b) => $a['index'] <=> $b['index']);

        // Build result array
        $totalTasks = count($taskList);
        $result = array_fill(0, $totalTasks, null);

        // Place anchors
        foreach ($anchors as $anchor) {
            $result[$anchor['index']] = [
                'id' => $anchor['task']->id,
                'title' => $anchor['task']->title,
                'milestone_name' => $anchor['task']->milestone?->name ?? 'Unassigned',
                'current_date' => $anchor['task']->due_date->toDateString(),
                'new_date' => $anchor['task']->due_date->toDateString(),
            ];
        }

        // Build segments between anchors for unanchored tasks
        $segments = [];
        $currentSegmentStart = $windowStart->copy();
        $currentSegmentTasks = [];

        for ($i = 0; $i < $totalTasks; $i++) {
            if ($result[$i] !== null) {
                // This is an anchor - finish current segment
                if (! empty($currentSegmentTasks)) {
                    $segmentEnd = $anchors[array_search($i, array_column($anchors, 'index'))]['date']->copy()->subDay();
                    if ($segmentEnd->lt($currentSegmentStart)) {
                        $segmentEnd = $currentSegmentStart->copy();
                    }
                    $segments[] = ['tasks' => $currentSegmentTasks, 'start' => $currentSegmentStart->copy(), 'end' => $segmentEnd];
                    $currentSegmentTasks = [];
                }
                $currentSegmentStart = $anchors[array_search($i, array_column($anchors, 'index'))]['date']->copy()->addDay();
                if ($currentSegmentStart->gt($windowEnd)) {
                    $currentSegmentStart = $windowEnd->copy();
                }
            } else {
                $currentSegmentTasks[] = ['index' => $i, 'task' => $taskList[$i]];
            }
        }

        // Final segment after last anchor
        if (! empty($currentSegmentTasks)) {
            $segments[] = ['tasks' => $currentSegmentTasks, 'start' => $currentSegmentStart->copy(), 'end' => $windowEnd->copy()];
        }

        // Distribute tasks within each segment
        foreach ($segments as $segment) {
            $segmentDates = $this->computeEvenDates(count($segment['tasks']), $segment['start'], $segment['end']);
            foreach ($segment['tasks'] as $j => $taskInfo) {
                $date = $segmentDates[$j];
                // Clamp to project window
                if ($date->lt($windowStart)) {
                    $date = $windowStart->copy();
                }
                if ($date->gt($windowEnd)) {
                    $date = $windowEnd->copy();
                }
                $result[$taskInfo['index']] = [
                    'id' => $taskInfo['task']->id,
                    'title' => $taskInfo['task']->title,
                    'milestone_name' => $taskInfo['task']->milestone?->name ?? 'Unassigned',
                    'current_date' => $taskInfo['task']->due_date?->toDateString(),
                    'new_date' => $date->toDateString(),
                ];
            }
        }

        return $result;
    }

    /**
     * Distribute tasks evenly across a date window.
     */
    protected function distributeEvenly(array $tasks, Carbon $windowStart, Carbon $windowEnd): array
    {
        $dates = $this->computeEvenDates(count($tasks), $windowStart, $windowEnd);

        return array_map(fn ($task, $i) => [
            'id' => $task->id,
            'title' => $task->title,
            'milestone_name' => $task->milestone?->name ?? 'Unassigned',
            'current_date' => $task->due_date?->toDateString(),
            'new_date' => $dates[$i]->toDateString(),
        ], $tasks, array_keys($tasks));
    }

    /**
     * Compute evenly spaced dates within a window.
     *
     * @return Carbon[]
     */
    protected function computeEvenDates(int $count, Carbon $start, Carbon $end): array
    {
        if ($count <= 0) {
            return [];
        }

        if ($count === 1) {
            return [$end->copy()];
        }

        $totalDays = max(0, $start->diffInDays($end));

        if ($totalDays === 0) {
            return array_fill(0, $count, $start->copy());
        }

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dayOffset = (int) round(($totalDays * ($i + 1)) / $count);
            $dates[] = $start->copy()->addDays($dayOffset);
        }

        return $dates;
    }
}
