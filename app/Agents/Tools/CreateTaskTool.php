<?php

namespace App\Agents\Tools;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Create a new task.
 *
 * This tool REQUIRES APPROVAL as it creates data.
 */
class CreateTaskTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Task';
    }

    public function description(): string
    {
        return 'Create a new task with title, description, and optional project/assignee. Returns the created task.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Task title (required)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Detailed description of the task',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the project to assign this task to',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Name of project (alternative to project_id)',
                ],
                'assignee_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the user to assign this task to',
                ],
                'assignee_name' => [
                    'type' => 'string',
                    'description' => 'Name of assignee (alternative to assignee_id)',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'urgent'],
                    'description' => 'Priority level (default: medium)',
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'Due date in Y-m-d format',
                ],
            ],
            'required' => ['title'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'project_name' => 'nullable|string',
            'assignee_id' => 'nullable|integer|exists:users,id',
            'assignee_name' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
        ];
    }

    public function requiresApproval(): bool
    {
        return true; // Creating data requires approval
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        // Resolve project by name if needed
        $projectId = $params['project_id'] ?? null;
        if (! $projectId && ! empty($params['project_name'])) {
            $project = Project::where('name', 'like', "%{$params['project_name']}%")->first();
            $projectId = $project?->id;
        }

        // Resolve assignee by name if needed
        $assigneeId = $params['assignee_id'] ?? null;
        if (! $assigneeId && ! empty($params['assignee_name'])) {
            $user = User::where('name', 'like', "%{$params['assignee_name']}%")->first();
            $assigneeId = $user?->id;
        }

        $task = Task::create([
            'title' => $params['title'],
            'description' => $params['description'] ?? null,
            'project_id' => $projectId,
            'assigned_to' => $assigneeId,
            'priority' => $params['priority'] ?? 'medium',
            'status' => 'pending',
            'due_date' => $params['due_date'] ?? null,
            'source' => 'ai',
        ]);

        return [
            'created' => true,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'project' => $task->project?->name,
                'assignee' => $task->assignee?->name,
            ],
        ];
    }
}
