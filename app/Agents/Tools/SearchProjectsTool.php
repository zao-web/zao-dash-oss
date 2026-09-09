<?php

namespace App\Agents\Tools;

use App\Models\Project;

/**
 * Search for projects.
 */
class SearchProjectsTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Projects';
    }

    public function description(): string
    {
        return 'Search for projects by name, description, or status. Returns matching projects with task counts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query for project names and descriptions',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'completed', 'on_hold', 'cancelled'],
                    'description' => 'Filter by project status',
                ],
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by client ID',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum results (default 10)',
                ],
            ],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'nullable|string|max:200',
            'status' => 'nullable|in:active,completed,on_hold,cancelled',
            'client_id' => 'nullable|integer|exists:clients,id',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function execute(array $params): array
    {
        $query = Project::query()
            ->with('client')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed')]);

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['client_id'])) {
            $query->where('client_id', $params['client_id']);
        }

        $limit = $params['limit'] ?? 10;
        $projects = $query->orderBy('updated_at', 'desc')->limit($limit)->get();

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status,
                'client' => $p->client?->name,
                'client_id' => $p->client_id,
                'client_slug' => $p->client?->slug,
                'tasks_count' => $p->tasks_count,
                'completed_tasks_count' => $p->completed_tasks_count,
                'budget' => $p->budget,
            ])->toArray(),
        ];
    }
}
