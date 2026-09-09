<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetTaskTool extends Tool
{
    protected string $name = 'get-task';

    protected string $title = 'Get Task Details';

    protected string $description = 'Get detailed information about a specific task including comments and activity.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:tasks,id',
        ]);

        $task = Task::with(['project.client', 'assignee', 'comments.user', 'activities'])->findOrFail($request->get('id'));

        return Response::structured([
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'estimated_hours' => $task->estimated_hours,
            'actual_hours' => $task->actual_hours,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
                'slug' => $task->project->slug,
            ] : null,
            'client' => $task->project?->client?->name,
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
            'source' => $task->source,
            'comments' => $task->comments->take(10)->map(fn ($c) => [
                'user' => $c->user?->name,
                'content' => $c->content,
                'created_at' => $c->created_at->diffForHumans(),
            ]),
            'recent_activity' => $task->activities->take(5)->map(fn ($a) => [
                'type' => $a->type,
                'description' => $a->description,
                'created_at' => $a->created_at->diffForHumans(),
            ]),
            'created_at' => $task->created_at->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Task ID'),
        ];
    }
}
