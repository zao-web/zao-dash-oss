<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateTaskTool extends Tool
{
    protected string $name = 'create-task';

    protected string $title = 'Create Task';

    protected string $description = 'Create a new task in a project.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'project_id' => 'required|exists:projects,id',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'assignee_id' => 'nullable|exists:users,id',
            'estimated_hours' => 'nullable|numeric|min:0',
        ]);

        $validated['status'] = 'pending';
        $validated['priority'] = $validated['priority'] ?? 'medium';
        $validated['source'] = 'mcp';

        $maxPosition = Task::where('project_id', $validated['project_id'])->max('position') ?? 0;
        $validated['position'] = $maxPosition + 1;

        $task = Task::create($validated);
        $project = Project::find($validated['project_id']);

        return Response::structured([
            'id' => $task->id,
            'title' => $task->title,
            'project' => $project->name,
            'status' => $task->status,
            'priority' => $task->priority,
            'message' => "Task '{$task->title}' created in project '{$project->name}'.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Task title'),
            'project_id' => $schema->integer()->required()->description('Project ID to add task to'),
            'description' => $schema->string()->description('Task description'),
            'priority' => $schema->string()->enum(['low', 'medium', 'high', 'urgent'])->description('Task priority (default: medium)'),
            'due_date' => $schema->string()->format('date')->description('Due date (YYYY-MM-DD)'),
            'assignee_id' => $schema->integer()->description('User ID to assign task to'),
            'estimated_hours' => $schema->number()->description('Estimated hours to complete'),
        ];
    }
}
