<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteTaskTool extends Tool
{
    protected string $name = 'delete-task';

    protected string $title = 'Delete Task';

    protected string $description = 'Delete a task by ID. This action cannot be undone.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:tasks,id',
            'confirm' => 'required|boolean',
        ]);

        if (! $request->get('confirm')) {
            return Response::text('Deletion cancelled. Set confirm: true to proceed with deletion.');
        }

        $task = Task::findOrFail($request->get('id'));
        $taskTitle = $task->title;
        $projectName = $task->project?->name ?? 'Unknown';

        $task->delete();

        return Response::structured([
            'deleted' => true,
            'task_id' => $request->get('id'),
            'task_title' => $taskTitle,
            'project' => $projectName,
            'message' => "Task '{$taskTitle}' has been deleted from project '{$projectName}'.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Task ID to delete'),
            'confirm' => $schema->boolean()->required()->description('Must be true to confirm deletion'),
        ];
    }
}
