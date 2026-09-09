<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\TimeEntry;

/**
 * Get unbilled time entries for a client.
 *
 * Helps agents understand what billable work exists before creating invoices.
 */
class GetUnbilledTimeTool extends BaseTool
{
    public function category(): string
    {
        return 'invoicing';
    }

    public function name(): string
    {
        return 'Get Unbilled Time';
    }

    public function description(): string
    {
        return 'Get unbilled billable time entries for a client. Use this to see what work can be invoiced before creating an invoice. Returns time entries grouped by project with totals.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the client to get unbilled time for (required)',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Filter to a specific project',
                ],
            ],
            'required' => ['client_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'project_id' => 'nullable|integer|exists:projects,id',
        ];
    }

    public function execute(array $params): array
    {
        $client = Client::findOrFail($params['client_id']);

        $query = TimeEntry::where('client_id', $params['client_id'])
            ->where('is_billable', true)
            ->where('is_billed', false)
            ->whereNotNull('hourly_rate')
            ->where('hours', '>', 0)
            ->with(['project:id,name', 'task:id,title', 'user:id,name'])
            ->orderBy('spent_date');

        if (! empty($params['project_id'])) {
            $query->where('project_id', $params['project_id']);
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            return [
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                ],
                'has_unbilled_time' => false,
                'message' => 'No unbilled time entries found for this client.',
                'entries' => [],
                'totals' => [
                    'hours' => 0,
                    'amount' => 0,
                ],
            ];
        }

        // Group by project
        $byProject = $entries->groupBy('project_id')->map(function ($projectEntries, $projectId) {
            $project = $projectEntries->first()->project;

            return [
                'project_id' => $projectId,
                'project_name' => $project?->name ?? 'No Project',
                'entries' => $projectEntries->map(fn ($e) => [
                    'id' => $e->id,
                    'date' => $e->spent_date->format('Y-m-d'),
                    'hours' => round($e->hours, 2),
                    'rate' => $e->hourly_rate,
                    'amount' => round($e->hours * $e->hourly_rate, 2),
                    'notes' => $e->notes,
                    'task' => $e->task?->title,
                    'user' => $e->user?->name,
                ])->values()->all(),
                'total_hours' => round($projectEntries->sum('hours'), 2),
                'total_amount' => round($projectEntries->sum(fn ($e) => $e->hours * $e->hourly_rate), 2),
            ];
        })->values()->all();

        $totalHours = $entries->sum('hours');
        $totalAmount = $entries->sum(fn ($e) => $e->hours * $e->hourly_rate);

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'has_unbilled_time' => true,
            'entry_count' => $entries->count(),
            'by_project' => $byProject,
            'totals' => [
                'hours' => round($totalHours, 2),
                'amount' => round($totalAmount, 2),
            ],
            'date_range' => [
                'from' => $entries->min('spent_date')?->format('Y-m-d'),
                'to' => $entries->max('spent_date')?->format('Y-m-d'),
            ],
        ];
    }
}
