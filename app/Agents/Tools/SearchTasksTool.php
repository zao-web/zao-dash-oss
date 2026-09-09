<?php

namespace App\Agents\Tools;

use App\Models\Task;

/**
 * Search for tasks by query.
 *
 * Deterministic search - no LLM reasoning involved.
 */
class SearchTasksTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Tasks';
    }

    public function description(): string
    {
        return 'Search for tasks by title, description, status, or priority. Returns matching tasks with their details.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to match against task titles and descriptions',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'in_progress', 'review', 'completed'],
                    'description' => 'Filter by task status',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'urgent'],
                    'description' => 'Filter by priority level',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results (default 10)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'nullable|string|max:200',
            'status' => 'nullable|in:pending,in_progress,review,completed',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function execute(array $params): array
    {
        $query = Task::query()->with(['project', 'assignee']);

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['priority'])) {
            $query->where('priority', $params['priority']);
        }

        $limit = $params['limit'] ?? 10;
        $tasks = $query->orderBy('updated_at', 'desc')->limit($limit)->get();

        return [
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'project' => $task->project?->name,
                'assignee' => $task->assignee?->name,
                'due_date' => $task->due_date?->format('M d, Y'),
            ])->toArray(),
        ];
    }
}
