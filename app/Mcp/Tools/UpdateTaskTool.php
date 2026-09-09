<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateTaskTool extends Tool
{
    protected string $name = 'update-task';

    protected string $title = 'Update Task';

    protected string $description = 'Update a task\'s details, status, priority, or assignment.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:tasks,id',
        ]);

        $task = Task::findOrFail($request->get('id'));

        $updateData = collect($request->all())
            ->only(['title', 'description', 'status', 'priority', 'due_date', 'assignee_id', 'estimated_hours', 'actual_hours'])
            ->filter(fn ($value) => ! is_null($value))
            ->toArray();

        if (empty($updateData)) {
            return Response::text('No fields to update provided.');
        }

        $task->update($updateData);

        return Response::structured([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'updated_fields' => array_keys($updateData),
            'message' => "Task '{$task->title}' updated successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Task ID to update'),
            'title' => $schema->string()->description('New title'),
            'description' => $schema->string()->description('New description'),
            'status' => $schema->string()->enum(['pending', 'in_progress', 'review', 'completed'])->description('New status'),
            'priority' => $schema->string()->enum(['low', 'medium', 'high', 'urgent'])->description('New priority'),
            'due_date' => $schema->string()->format('date')->description('New due date (YYYY-MM-DD)'),
            'assignee_id' => $schema->integer()->description('New assignee user ID'),
            'estimated_hours' => $schema->number()->description('Estimated hours'),
            'actual_hours' => $schema->number()->description('Actual hours spent'),
        ];
    }
}
