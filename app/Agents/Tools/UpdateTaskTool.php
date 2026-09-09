<?php

namespace App\Agents\Tools;

use App\Models\Task;

/**
 * Update an existing task.
 */
class UpdateTaskTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Update Task';
    }

    public function description(): string
    {
        return 'Update a task status, priority, or other fields. Use task_id or search by title.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'ID of task to update',
                ],
                'task_title' => [
                    'type' => 'string',
                    'description' => 'Title to search for (alternative to task_id)',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'in_progress', 'review', 'completed', 'cancelled'],
                    'description' => 'New status',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'urgent'],
                    'description' => 'New priority',
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'New due date (Y-m-d format)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'task_id' => 'nullable|integer|exists:tasks,id',
            'task_title' => 'nullable|string',
            'status' => 'nullable|in:pending,in_progress,review,completed,cancelled',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'low';
    }

    public function execute(array $params): array
    {
        // Find task
        $task = null;
        if (! empty($params['task_id'])) {
            $task = Task::find($params['task_id']);
        } elseif (! empty($params['task_title'])) {
            $task = Task::where('title', 'like', "%{$params['task_title']}%")->first();
        }

        if (! $task) {
            return [
                'updated' => false,
                'error' => 'Task not found',
            ];
        }

        $updates = [];
        if (isset($params['status'])) {
            $updates['status'] = $params['status'];
        }
        if (isset($params['priority'])) {
            $updates['priority'] = $params['priority'];
        }
        if (isset($params['due_date'])) {
            $updates['due_date'] = $params['due_date'];
        }

        if (empty($updates)) {
            return [
                'updated' => false,
                'error' => 'No updates provided',
            ];
        }

        $task->update($updates);

        return [
            'updated' => true,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
            ],
        ];
    }
}
