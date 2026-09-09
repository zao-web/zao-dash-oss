<?php

namespace App\Mcp\Tools;

use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class BulkUpdateTasksTool extends Tool
{
    protected string $name = 'bulk-update-tasks';

    protected string $title = 'Bulk Update Tasks';

    protected string $description = 'Update multiple tasks at once. Useful for bulk assignment, status changes, or priority updates.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'task_ids' => 'required|array|min:1',
            'task_ids.*' => 'integer|exists:tasks,id',
        ]);

        $taskIds = $request->get('task_ids');
        $updateData = collect($request->all())
            ->only(['status', 'priority', 'assignee_id', 'due_date'])
            ->filter(fn ($value) => ! is_null($value))
            ->toArray();

        if (empty($updateData)) {
            return Response::text('No fields to update provided. Specify at least one of: status, priority, assignee_id, due_date.');
        }

        // Map assignee_id to assigned_to (database column name)
        if (isset($updateData['assignee_id'])) {
            $updateData['assigned_to'] = $updateData['assignee_id'];
            unset($updateData['assignee_id']);
        }

        $updated = Task::whereIn('id', $taskIds)->update($updateData);

        $tasks = Task::whereIn('id', $taskIds)
            ->with('assignee')
            ->get()
            ->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'assignee' => $task->assignee?->name,
            ]);

        return Response::structured([
            'updated_count' => $updated,
            'updated_fields' => array_keys($updateData),
            'tasks' => $tasks,
            'message' => "Updated {$updated} task(s) successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_ids' => $schema->array()->items($schema->integer())->required()->description('Array of task IDs to update'),
            'status' => $schema->string()->enum(['pending', 'in_progress', 'review', 'completed'])->description('New status for all tasks'),
            'priority' => $schema->string()->enum(['low', 'medium', 'high', 'urgent'])->description('New priority for all tasks'),
            'assignee_id' => $schema->integer()->description('User ID to assign all tasks to'),
            'due_date' => $schema->string()->format('date')->description('New due date for all tasks (YYYY-MM-DD)'),
        ];
    }
}
