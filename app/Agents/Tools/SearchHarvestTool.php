<?php

namespace App\Agents\Tools;

use App\Models\HarvestProject;
use App\Models\TimeEntry;

/**
 * Search Harvest time entries for project research.
 *
 * Use for understanding work done, time invested, and project scope
 * when creating case studies or project summaries.
 */
class SearchHarvestTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function id(): string
    {
        return 'search-harvest';
    }

    public function name(): string
    {
        return 'Search Harvest Time';
    }

    public function description(): string
    {
        return 'Search Harvest time entries to understand work done on projects. Returns hours by task, team members involved, and project timeline. Use for case study research and project analysis.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Project name to search (partial match supported)',
                ],
                'client_name' => [
                    'type' => 'string',
                    'description' => 'Client name to search (partial match supported)',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Start date (YYYY-MM-DD). Defaults to 2 years ago.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'End date (YYYY-MM-DD). Defaults to today.',
                ],
                'include_notes' => [
                    'type' => 'boolean',
                    'description' => 'Include time entry notes (useful for understanding work done)',
                    'default' => true,
                ],
            ],
            'required' => [],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function execute(array $params): array
    {
        $projectName = $params['project_name'] ?? null;
        $clientName = $params['client_name'] ?? null;
        $fromDate = $params['from_date'] ?? now()->subYears(2)->toDateString();
        $toDate = $params['to_date'] ?? now()->toDateString();
        $includeNotes = $params['include_notes'] ?? true;

        // First try local time entries
        $localResult = $this->searchLocalTimeEntries($projectName, $clientName, $fromDate, $toDate, $includeNotes);

        if ($localResult['total_entries'] > 0) {
            return $localResult;
        }

        // Try Harvest projects table
        $harvestResult = $this->searchHarvestProjects($projectName, $clientName);

        if (! empty($harvestResult['projects'])) {
            return [
                'success' => true,
                'source' => 'harvest_projects',
                'note' => 'Found Harvest projects but no synced time entries. Run Harvest sync to get detailed time data.',
                'projects' => $harvestResult['projects'],
                'suggestion' => 'Use the Harvest sync to import time entries, or search the Harvest web interface directly.',
            ];
        }

        return [
            'success' => true,
            'source' => 'none',
            'note' => 'No matching projects or time entries found.',
            'total_entries' => 0,
            'suggestion' => $projectName || $clientName
                ? 'The project may not be synced. Try a web search or Slack search for project context.'
                : 'Provide a project_name or client_name to search.',
        ];
    }

    protected function searchLocalTimeEntries(
        ?string $projectName,
        ?string $clientName,
        string $fromDate,
        string $toDate,
        bool $includeNotes
    ): array {
        $query = TimeEntry::query()
            ->whereBetween('spent_date', [$fromDate, $toDate]);

        if ($projectName) {
            $query->whereHas('project', fn ($q) => $q->where('name', 'like', "%{$projectName}%")
            );
        }

        if ($clientName) {
            $query->whereHas('project.client', fn ($q) => $q->where('name', 'like', "%{$clientName}%")
            );
        }

        $entries = $query->with(['project', 'user', 'taskCategory'])->get();

        if ($entries->isEmpty()) {
            return ['total_entries' => 0];
        }

        // Aggregate by project
        $byProject = $entries->groupBy('project_id')->map(function ($projectEntries) use ($includeNotes) {
            $project = $projectEntries->first()->project;

            $byTask = $projectEntries->groupBy('task_category_id')->map(function ($taskEntries) {
                $task = $taskEntries->first()->taskCategory;

                return [
                    'task' => $task?->name ?? 'Uncategorized',
                    'hours' => round($taskEntries->sum('hours'), 2),
                    'entries' => $taskEntries->count(),
                ];
            })->sortByDesc('hours')->values();

            $byPerson = $projectEntries->groupBy('user_id')->map(function ($userEntries) {
                $user = $userEntries->first()->user;

                return [
                    'name' => $user?->name ?? 'Unknown',
                    'hours' => round($userEntries->sum('hours'), 2),
                ];
            })->sortByDesc('hours')->values();

            $result = [
                'project' => $project?->name ?? 'Unknown Project',
                'client' => $project?->client?->name ?? 'Unknown Client',
                'total_hours' => round($projectEntries->sum('hours'), 2),
                'date_range' => [
                    'first' => $projectEntries->min('spent_date'),
                    'last' => $projectEntries->max('spent_date'),
                ],
                'by_task' => $byTask->take(10)->toArray(),
                'by_person' => $byPerson->take(10)->toArray(),
            ];

            if ($includeNotes) {
                $result['sample_notes'] = $projectEntries
                    ->filter(fn ($e) => ! empty($e->notes))
                    ->take(10)
                    ->map(fn ($e) => [
                        'date' => $e->spent_date,
                        'task' => $e->taskCategory?->name,
                        'notes' => $e->notes,
                    ])
                    ->values()
                    ->toArray();
            }

            return $result;
        })->values();

        return [
            'success' => true,
            'source' => 'time_entries',
            'total_entries' => $entries->count(),
            'total_hours' => round($entries->sum('hours'), 2),
            'date_range' => [$fromDate, $toDate],
            'projects' => $byProject->toArray(),
        ];
    }

    protected function searchHarvestProjects(?string $projectName, ?string $clientName): array
    {
        $query = HarvestProject::query();

        if ($projectName) {
            $query->where('name', 'like', "%{$projectName}%");
        }

        if ($clientName) {
            $query->where('client_name', 'like', "%{$clientName}%");
        }

        $projects = $query->limit(10)->get(['name', 'client_name', 'is_active', 'harvest_project_id']);

        return [
            'projects' => $projects->map(fn ($p) => [
                'name' => $p->name,
                'client' => $p->client_name,
                'active' => $p->is_active,
                'harvest_id' => $p->harvest_project_id,
            ])->toArray(),
        ];
    }
}
