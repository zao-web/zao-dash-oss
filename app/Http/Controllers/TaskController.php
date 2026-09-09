<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAgentTasksJob;
use App\Models\Agent;
use App\Models\CommentReaction;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\TaskAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:pending,in_progress,review,completed',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'project_id' => 'nullable|exists:projects,id',
            'assigned_to' => 'nullable|integer',
            'assignee_type' => 'nullable|in:user,agent,client_contact',
            'due_date' => 'nullable|date',
            'source' => 'nullable|in:manual,meeting-parser,agent',
            'source_session_id' => 'nullable|string|max:255',
            'milestone_id' => 'nullable|exists:milestones,id',
        ]);

        // Default assignee_type to 'user' when assigned_to is set
        if (! empty($validated['assigned_to']) && empty($validated['assignee_type'])) {
            $validated['assignee_type'] = 'user';
        }

        if (empty($validated['assigned_to'])) {
            $validated['assignee_type'] = null;
        }

        $task = Task::create($validated);

        // If assigned to an agent, create an AgentTask and queue orchestration.
        if ($task->assignee_type === 'agent' && $task->assigned_to) {
            $agent = Agent::find($task->assigned_to);
            if ($agent) {
                $taskAgentService = app(TaskAgentService::class);
                $taskAgentService->assignAgentToTask($task, $agent, Auth::user());
                $this->queueTaskAgentProcessing();
            }
        }

        return redirect()->back()->with('success', 'Task created successfully.');
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:pending,in_progress,review,completed',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'project_id' => 'nullable|exists:projects,id',
            'assigned_to' => 'nullable|integer',
            'assignee_type' => 'nullable|in:user,agent,client_contact',
            'due_date' => 'nullable|date',
            'source' => 'nullable|in:manual,meeting-parser,agent',
            'source_session_id' => 'nullable|string|max:255',
            'milestone_id' => 'nullable|exists:milestones,id',
        ]);

        if (! empty($validated['assigned_to']) && empty($validated['assignee_type'])) {
            $validated['assignee_type'] = 'user';
        }

        if (empty($validated['assigned_to'])) {
            $validated['assignee_type'] = null;
        }

        $task->update($validated);

        return redirect()->back()->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->back()->with('success', 'Task deleted successfully.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,review,completed',
            'position' => 'nullable|integer|min:0',
        ]);

        $oldStatus = $task->status;
        $task->update($validated);

        // Log status change activity
        if ($oldStatus !== $validated['status'] && Auth::check()) {
            TaskComment::logStatusChange($task, Auth::id(), $oldStatus, $validated['status']);
        }

        return redirect()->back()->with('success', 'Task status updated successfully.');
    }

    public function updatePriority(Request $request, Task $task): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'priority' => 'required|in:low,medium,high,urgent',
        ]);

        $task->update($validated);

        return redirect()->back()->with('success', 'Task priority updated successfully.');
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'tasks' => 'required|array',
            'tasks.*.id' => 'required|exists:tasks,id',
            'tasks.*.position' => 'required|integer|min:0',
            'tasks.*.status' => 'required|in:pending,in_progress,review,completed',
        ]);

        foreach ($validated['tasks'] as $taskData) {
            Task::where('id', $taskData['id'])->update([
                'position' => $taskData['position'],
                'status' => $taskData['status'],
            ]);
        }

        return redirect()->back()->with('success', 'Tasks reordered successfully.');
    }

    public function assign(Request $request, Task $task)
    {
        $validated = $request->validate([
            'assigned_to' => 'nullable|integer',
            'assignee_type' => 'nullable|in:user,agent,client_contact',
        ]);

        $assigneeType = $validated['assignee_type'] ?? 'user';
        $assigneeId = $validated['assigned_to'];

        // Validate the ID exists in the correct table
        if ($assigneeId) {
            $exists = match ($assigneeType) {
                'agent' => Agent::where('id', $assigneeId)->exists(),
                'client_contact' => \App\Models\ClientContact::where('id', $assigneeId)->exists(),
                default => \App\Models\User::where('id', $assigneeId)->exists(),
            };

            if (! $exists) {
                return redirect()->back()->withErrors(['assigned_to' => 'The selected assignee does not exist.']);
            }
        }

        $oldAssigneeInfo = $task->assignee_info;
        $oldAssigneeId = $task->assigned_to;
        $oldAssigneeType = $task->assignee_type;

        $task->update([
            'assigned_to' => $assigneeId,
            'assignee_type' => $assigneeId ? $assigneeType : null,
        ]);

        $assignmentChanged = $oldAssigneeId !== $assigneeId || $oldAssigneeType !== $assigneeType;

        if ($assignmentChanged && Auth::check()) {
            TaskComment::logAssignment(
                $task,
                Auth::id(),
                $oldAssigneeId,
                $assigneeId,
                $oldAssigneeType ?? 'user',
                $assigneeType
            );

            // When assigning to an agent, create an AgentTask and queue Symphony orchestration.
            if ($assigneeType === 'agent' && $assigneeId) {
                $agent = Agent::findOrFail($assigneeId);
                $taskAgentService = app(TaskAgentService::class);
                $taskAgentService->assignAgentToTask($task, $agent, Auth::user());
                $this->queueTaskAgentProcessing();
            }
        }

        return redirect()->back()->with('success', 'Task assigned successfully.');
    }

    /**
     * Get task details with videos and comments for modal.
     */
    public function show(Task $task)
    {
        $task->load([
            'videos' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'comments.user',
            'comments.reactions.user',
        ]);

        return response()->json([
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'videos' => $task->videos->map(fn ($v) => [
                    'id' => $v->id,
                    'title' => $v->title,
                    'duration' => $v->duration,
                    'view_count' => $v->view_count,
                    'status' => $v->status,
                    'share_url' => "/v/{$v->share_token}",
                    'thumbnail_url' => $v->thumbnail_path ? "/v/{$v->share_token}/thumbnail" : null,
                    'created_at' => $v->created_at->diffForHumans(),
                ]),
                'comments' => $task->comments->map(fn ($c) => [
                    'id' => $c->id,
                    'type' => $c->type,
                    'content' => $c->content,
                    'metadata' => $c->metadata,
                    'user' => $c->user ? [
                        'id' => $c->user->id,
                        'name' => $c->user->name,
                    ] : [
                        'id' => null,
                        'name' => $c->metadata['author_name'] ?? 'External User',
                    ],
                    'reactions' => $c->grouped_reactions,
                    'created_at' => $c->created_at->diffForHumans(),
                ]),
            ],
        ]);
    }

    /**
     * Add a comment to a task.
     */
    public function storeComment(Request $request, Task $task)
    {
        $validated = $request->validate([
            'content' => 'required|string|max:50000', // Allow longer content for rich HTML
        ]);

        $comment = TaskComment::createComment($task, Auth::id(), $validated['content']);
        $comment->load('user');

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'type' => $comment->type,
                'content' => $comment->content,
                'metadata' => $comment->metadata,
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                ],
                'reactions' => [],
                'created_at' => $comment->created_at->diffForHumans(),
            ],
        ], 201);
    }

    /**
     * Toggle a reaction on a comment.
     */
    public function toggleReaction(Request $request, Task $task, TaskComment $comment)
    {
        if ($comment->task_id !== $task->id) {
            abort(404);
        }

        $validated = $request->validate([
            'emoji' => 'required|string|max:32',
        ]);

        $existing = CommentReaction::where([
            'task_comment_id' => $comment->id,
            'user_id' => Auth::id(),
            'emoji' => $validated['emoji'],
        ])->first();

        if ($existing) {
            $existing->delete();
            $action = 'removed';
        } else {
            CommentReaction::create([
                'task_comment_id' => $comment->id,
                'user_id' => Auth::id(),
                'emoji' => $validated['emoji'],
            ]);
            $action = 'added';
        }

        $comment->load('reactions.user');

        return response()->json([
            'action' => $action,
            'reactions' => $comment->grouped_reactions,
        ]);
    }

    public function destroyComment(Task $task, TaskComment $comment)
    {
        if ($comment->task_id !== $task->id) {
            abort(404);
        }

        if ($comment->user_id !== Auth::id()) {
            abort(403);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    public function assignAgent(Request $request, Task $task)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
        ]);

        $agent = \App\Models\Agent::findOrFail($validated['agent_id']);
        $taskAgentService = app(\App\Services\TaskAgentService::class);

        $agentTask = $taskAgentService->assignAgentToTask($task, $agent, Auth::user());

        return response()->json([
            'message' => 'Agent assigned successfully',
            'agent_task' => [
                'id' => $agentTask->id,
                'status' => $agentTask->status,
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                ],
            ],
        ]);
    }

    public function executeAgent(Request $request, Task $task)
    {
        $validated = $request->validate([
            'agent_id' => 'nullable|exists:agents,id',
        ]);

        $taskAgentService = app(\App\Services\TaskAgentService::class);

        $agentTask = $task->agentTasks()
            ->where('status', \App\Models\AgentTask::STATUS_PENDING)
            ->latest()
            ->first();

        if (! $agentTask && isset($validated['agent_id'])) {
            $agent = \App\Models\Agent::findOrFail($validated['agent_id']);
            $agentTask = $taskAgentService->assignAgentToTask($task, $agent, Auth::user());
        }

        if (! $agentTask) {
            return response()->json(['error' => 'No pending agent task found'], 400);
        }

        $this->queueTaskAgentProcessing();

        return response()->json([
            'message' => 'Agent execution queued',
            'agent_task' => [
                'id' => $agentTask->id,
                'status' => $agentTask->status,
            ],
        ]);
    }

    protected function queueTaskAgentProcessing(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        ProcessAgentTasksJob::dispatchAfterResponse();
    }

    public function getActivities(Task $task)
    {
        $activities = $task->activities()
            ->with(['user', 'agent', 'agentRun'])
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'description' => $a->description,
                'metadata' => $a->metadata,
                'hours_logged' => $a->hours_logged,
                'hours_estimated' => $a->hours_estimated,
                'pr_url' => $a->pr_url,
                'pr_number' => $a->pr_number,
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'agent' => $a->agent ? ['id' => $a->agent->id, 'name' => $a->agent->name] : null,
                'created_at' => $a->created_at->diffForHumans(),
            ]);

        return response()->json(['activities' => $activities]);
    }

    public function getAgentStatus(Task $task)
    {
        $latestTask = $task->latestAgentTask;

        if (! $latestTask) {
            return response()->json(['agent_task' => null]);
        }

        $latestTask->load(['agent', 'agentRun']);

        return response()->json([
            'agent_task' => [
                'id' => $latestTask->id,
                'status' => $latestTask->status,
                'priority' => $latestTask->priority,
                'estimated_human_hours' => $latestTask->estimated_human_hours,
                'actual_agent_seconds' => $latestTask->actual_agent_seconds,
                'harvest_time_entry_id' => $latestTask->harvest_time_entry_id,
                'agent' => [
                    'id' => $latestTask->agent->id,
                    'name' => $latestTask->agent->name,
                    'slug' => $latestTask->agent->slug,
                ],
                'run' => $latestTask->agentRun ? [
                    'id' => $latestTask->agentRun->id,
                    'status' => $latestTask->agentRun->status,
                    'cost_usd' => $latestTask->agentRun->cost_usd,
                ] : null,
                'started_at' => $latestTask->started_at?->diffForHumans(),
                'completed_at' => $latestTask->completed_at?->diffForHumans(),
            ],
        ]);
    }
}
