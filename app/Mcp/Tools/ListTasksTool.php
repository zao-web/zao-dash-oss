<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListTasksTool extends Tool
{
    protected string $name = 'list-tasks';

    protected string $title = 'List Tasks';

    protected string $description = 'List tasks with optional filtering by status, project, or assignee.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $projectId = $request->get('project_id');
        $assigneeId = $request->get('assignee_id');
        $unassigned = $request->get('unassigned', false);
        $limit = $request->get('limit', 50);

        $query = Task::with(['project.client', 'assignee'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'review' THEN 3 WHEN 'completed' THEN 4 END")
            ->orderBy('priority', 'desc')
            ->orderBy('position');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($unassigned) {
            $query->whereNull('assigned_to');
        } elseif ($assigneeId) {
            $query->where('assigned_to', $assigneeId);
        }

        $tasks = $query->limit($limit)->get()->map(fn ($task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'project' => $task->project?->name,
            'client' => $task->project?->client?->name,
            'assignee' => $task->assignee_info['name'] ?? null,
            'assignee_type' => $task->assignee_info['type'] ?? null,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'is_overdue' => $task->due_date && $task->due_date->isPast() && $task->status !== 'completed',
            'has_active_agent_task' => $task->hasActiveAgentTask(),
        ]);

        return Response::structured([
            'tasks' => $tasks,
            'total' => $tasks->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['pending', 'in_progress', 'review', 'completed', 'all'])
                ->description('Filter by status'),
            'project_id' => $schema->integer()->description('Filter by project ID'),
            'assignee_id' => $schema->integer()->description('Filter by assignee user ID'),
            'unassigned' => $schema->boolean()->description('Filter for unassigned tasks only (default: false)'),
            'limit' => $schema->integer()->description('Maximum tasks to return (default: 50)'),
        ];
    }
}
