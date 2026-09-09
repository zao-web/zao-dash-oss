<?php

namespace App\Http\Controllers;

use App\Http\Requests\DistributeTaskDatesRequest;
use App\Models\Project;
use App\Services\Harvest\HarvestApiService;
use App\Services\TaskDateDistributionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function __construct(
        protected HarvestApiService $harvestService
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,on_hold,completed,archived',
            'type' => 'nullable|in:retainer,project,support,time_materials',
            'budget' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'budget_hours' => 'nullable|numeric|min:0',
            'github_repo' => 'nullable|string|max:255',
            'notion_page_id' => 'nullable|string|max:255',
            'slack_channel_id' => 'nullable|exists:slack_channels,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Handle estimated_hours from frontend (maps to budget_hours in database)
        if ($request->has('estimated_hours') && ! isset($validated['budget_hours'])) {
            $validated['budget_hours'] = $request->input('estimated_hours');
        }

        $validated['slug'] = Str::slug($validated['name']);

        $project = Project::create($validated);

        $this->syncProjectToHarvest($project);

        return redirect()->back()->with('success', 'Project created successfully.');
    }

    protected function syncProjectToHarvest(Project $project): void
    {
        $user = \App\Models\User::whereHas('harvestCredential')->first();
        if (! $user) {
            Log::info('No user with Harvest credentials, skipping project sync', [
                'project_id' => $project->id,
            ]);

            return;
        }

        $client = $project->client;
        if (! $client) {
            Log::warning('Project has no client, skipping Harvest sync', [
                'project_id' => $project->id,
            ]);

            return;
        }

        Log::info('Attempting to sync project to Harvest', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'client_name' => $client->name,
        ]);

        try {
            $harvestClientId = $this->harvestService->findOrCreateClient($user, $client);

            // Map project type to Harvest billing settings
            $harvestData = [
                'harvest_client_id' => $harvestClientId,
                'name' => $project->name,
                'code' => $project->slug,
                'budget' => $project->budget,
                'notes' => $project->description,
            ];

            // Configure billing based on project type
            switch ($project->type) {
                case 'time_materials':
                    // Time & Materials: bill by hours at project rate, budget by total hours
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['is_fixed_fee'] = false;
                    if ($project->hourly_rate) {
                        $harvestData['hourly_rate'] = $project->hourly_rate;
                    }
                    break;
                case 'project':
                    // Fixed-price: fixed fee project
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['is_fixed_fee'] = true;
                    if ($project->budget) {
                        $harvestData['fee'] = $project->budget;
                    }
                    break;
                case 'retainer':
                    // Retainer: hourly billing with monthly budget reset
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['budget_is_monthly'] = true;
                    if ($project->hourly_rate) {
                        $harvestData['hourly_rate'] = $project->hourly_rate;
                    }
                    break;
                default:
                    // Default settings
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
            }

            $harvestProject = $this->harvestService->createProject($user, $harvestData);

            $project->update([
                'harvest_project_id' => $harvestProject['id'],
            ]);

            $this->harvestService->linkProject(
                $harvestProject['id'],
                $project->id,
                $client->id
            );

            Log::info('Project synced to Harvest', [
                'project_id' => $project->id,
                'harvest_project_id' => $harvestProject['id'],
                'harvest_client_id' => $harvestClientId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to sync project to Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,on_hold,completed,archived',
            'type' => 'nullable|in:retainer,project,support,time_materials',
            'budget' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'budget_hours' => 'nullable|numeric|min:0',
            'github_repo' => 'nullable|string|max:255',
            'notion_page_id' => 'nullable|string|max:255',
            'slack_channel_id' => 'nullable|exists:slack_channels,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Handle estimated_hours from frontend (maps to budget_hours in database)
        if ($request->has('estimated_hours') && ! isset($validated['budget_hours'])) {
            $validated['budget_hours'] = $request->input('estimated_hours');
        }

        if ($validated['name'] !== $project->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $project->update($validated);

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted successfully.');
    }

    public function archive(Project $project)
    {
        $project->archive();

        return redirect()->back()->with('success', "Project '{$project->name}' archived.");
    }

    public function unarchive(Project $project)
    {
        $project->unarchive();

        return redirect()->back()->with('success', "Project '{$project->name}' restored.");
    }

    public function updateStatus(Request $request, Project $project)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,on_hold,completed,archived',
        ]);

        $project->update(['status' => $validated['status']]);

        return redirect()->back()->with('success', 'Project status updated successfully.');
    }

    public function syncToHarvest(Project $project)
    {
        // Check if already synced
        if ($project->harvest_project_id) {
            return redirect()->back()->with('info', 'Project is already synced to Harvest.');
        }

        // Check for Harvest credentials
        $user = \App\Models\User::whereHas('harvestCredential')->first();
        if (! $user) {
            return redirect()->back()->with('error', 'No Harvest credentials found. Please connect Harvest first.');
        }

        // Check for client
        $client = $project->client;
        if (! $client) {
            return redirect()->back()->with('error', 'Project must have a client to sync to Harvest.');
        }

        try {
            $harvestClientId = $this->harvestService->findOrCreateClient($user, $client);

            // Map project type to Harvest billing settings
            $harvestData = [
                'harvest_client_id' => $harvestClientId,
                'name' => $project->name,
                'code' => $project->slug,
                'budget' => $project->budget,
                'notes' => $project->description,
            ];

            // Configure billing based on project type
            switch ($project->type) {
                case 'time_materials':
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['is_fixed_fee'] = false;
                    if ($project->hourly_rate) {
                        $harvestData['hourly_rate'] = $project->hourly_rate;
                    }
                    break;
                case 'project':
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['is_fixed_fee'] = true;
                    if ($project->budget) {
                        $harvestData['fee'] = $project->budget;
                    }
                    break;
                case 'retainer':
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
                    $harvestData['budget_is_monthly'] = true;
                    if ($project->hourly_rate) {
                        $harvestData['hourly_rate'] = $project->hourly_rate;
                    }
                    break;
                default:
                    $harvestData['bill_by'] = 'Project';
                    $harvestData['budget_by'] = 'project';
            }

            $harvestProject = $this->harvestService->createProject($user, $harvestData);

            $project->update([
                'harvest_project_id' => $harvestProject['id'],
            ]);

            $this->harvestService->linkProject(
                $harvestProject['id'],
                $project->id,
                $client->id
            );

            Log::info('Project manually synced to Harvest', [
                'project_id' => $project->id,
                'harvest_project_id' => $harvestProject['id'],
            ]);

            return redirect()->back()->with('success', 'Project synced to Harvest successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to manually sync project to Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to sync to Harvest: '.$e->getMessage());
        }
    }

    public function distributeDates(DistributeTaskDatesRequest $request, Project $project)
    {
        if (! $project->start_date || ! $project->end_date) {
            return redirect()->back()->with('error', 'Please set project start and end dates first.');
        }

        $service = new TaskDateDistributionService;
        $result = $service->distribute($project, (bool) $request->input('overwrite_existing', false));

        return redirect()->back()->with('success', "Dates assigned: {$result['tasks_updated']} tasks and {$result['milestones_updated']} milestones updated.");
    }

    public function previewDistribution(Request $request, Project $project)
    {
        if (! $project->start_date || ! $project->end_date) {
            return response()->json(['error' => 'Project must have start and end dates set.'], 422);
        }

        $service = new TaskDateDistributionService;
        $plan = $service->preview($project, (bool) $request->input('overwrite_existing', false));

        return response()->json($plan);
    }

    public function timelineData(Request $request, Project $project)
    {
        if (! $project->start_date || ! $project->end_date) {
            return response()->json(['error' => 'Project must have start and end dates set.'], 422);
        }

        $project->load(['milestones', 'tasks']);

        $totalTasks = $project->tasks->count();
        $completedTasks = $project->tasks->where('status', 'completed')->count();
        $durationDays = max(1, $project->project_duration_days);
        $daysElapsed = $project->days_elapsed;
        $daysRemaining = $project->days_remaining;

        $timeProgressPct = round(min(100, ($daysElapsed / $durationDays) * 100), 1);
        $taskProgressPct = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;

        // Build milestone data with timeline positions
        $milestones = $project->milestones->sortBy('id')->values()->map(function ($milestone) use ($project, $durationDays) {
            $milestoneTasks = $project->tasks->where('milestone_id', $milestone->id);
            $completedCount = $milestoneTasks->where('status', 'completed')->count();
            $totalCount = $milestoneTasks->count();

            // Calculate position on timeline based on task due dates or even distribution
            $startPct = 0;
            $endPct = 100;

            if ($milestone->due_date) {
                $endPct = min(100, round(($project->start_date->diffInDays($milestone->due_date) / $durationDays) * 100, 1));
            }

            $status = $milestone->status;
            if ($status !== 'completed' && $completedCount === $totalCount && $totalCount > 0) {
                $status = 'completed';
            }

            return [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'status' => $status,
                'due_date' => $milestone->due_date?->format('M d, Y'),
                'start_pct' => $startPct,
                'end_pct' => $endPct,
                'tasks_count' => $totalCount,
                'completed_tasks_count' => $completedCount,
                'progress_pct' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0,
            ];
        });

        // Calculate milestone start positions based on previous milestone end positions
        $prevEnd = 0;
        $milestones = $milestones->map(function ($m) use (&$prevEnd) {
            $m['start_pct'] = $prevEnd;
            $prevEnd = $m['end_pct'];

            return $m;
        });

        // Build completion series (weekly snapshots based on task completion)
        $completionSeries = [];
        $idealSeries = [];

        $startDate = $project->start_date->copy();
        $endDate = $project->end_date->copy();
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate) && $currentDate->lte(now())) {
            $completedByDate = $project->tasks->filter(function ($task) use ($currentDate) {
                return $task->status === 'completed' && $task->updated_at && $task->updated_at->lte($currentDate->endOfDay());
            })->count();

            $completionSeries[] = [
                'date' => $currentDate->toDateString(),
                'completed' => $completedByDate,
                'total' => $totalTasks,
            ];

            $currentDate->addWeek();
        }

        // Add a "today" data point if the last snapshot doesn't include today
        $lastSnapshotDate = ! empty($completionSeries) ? end($completionSeries)['date'] : null;
        if ($lastSnapshotDate && $lastSnapshotDate !== now()->toDateString() && now()->lte($endDate)) {
            $completionSeries[] = [
                'date' => now()->toDateString(),
                'completed' => $completedTasks,
                'total' => $totalTasks,
            ];
        }

        // Build estimated progress line (linear from 0 to totalTasks)
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $daysSinceStart = $startDate->diffInDays($currentDate);
            $idealSeries[] = [
                'date' => $currentDate->toDateString(),
                'value' => round(($daysSinceStart / $durationDays) * $totalTasks, 1),
            ];

            $currentDate->addWeek();
        }

        // Ensure ideal series covers at least as many points as completion series
        if (count($completionSeries) > count($idealSeries)) {
            $lastCompletionDate = end($completionSeries)['date'];
            $daysSinceStart = $startDate->diffInDays($lastCompletionDate);
            $idealSeries[] = [
                'date' => $lastCompletionDate,
                'value' => round(($daysSinceStart / $durationDays) * $totalTasks, 1),
            ];
        }

        return response()->json([
            'project' => [
                'start_date' => $project->start_date->toDateString(),
                'end_date' => $project->end_date->toDateString(),
                'time_progress_pct' => $timeProgressPct,
                'task_progress_pct' => $taskProgressPct,
                'timeline_status' => $project->timeline_status,
                'days_elapsed' => $daysElapsed,
                'days_remaining' => $daysRemaining,
            ],
            'milestones' => $milestones,
            'completion_series' => $completionSeries,
            'ideal_series' => $idealSeries,
        ]);
    }
}
