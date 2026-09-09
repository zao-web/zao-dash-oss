<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalTaskController extends Controller
{
    /**
     * Resolve the portal client (from impersonation or the user's own client).
     */
    private function portalClient(Request $request): Client
    {
        return $request->attributes->get('portal_client') ?? $request->user()->client;
    }

    /**
     * Verify task belongs to the portal client's projects.
     */
    private function authorizeTask(Request $request, Task $task): void
    {
        $client = $this->portalClient($request);
        $task->loadMissing('project');

        if ($task->project?->client_id !== $client->id) {
            abort(403, 'This task does not belong to your project.');
        }
    }

    /**
     * Get task details with comments.
     */
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->load(['comments.user', 'comments.reactions.user']);

        return response()->json([
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->format('M d, Y'),
                'estimated_hours' => $task->estimated_hours,
                'assignee' => $this->sanitizeAssignee($task->assignee_info),
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
                        'name' => $c->metadata['author_name'] ?? 'Team',
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
    public function storeComment(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $validated = $request->validate([
            'content' => 'required|string|max:10000',
        ]);

        $comment = TaskComment::createComment($task, $request->user()->id, $validated['content']);
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
     * Reassign a task (client contacts and team members only, no agents).
     */
    public function assign(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $validated = $request->validate([
            'assigned_to' => 'nullable|integer',
            'assignee_type' => 'nullable|in:user,client_contact',
        ]);

        $assigneeId = $validated['assigned_to'] ?? null;
        $assigneeType = $validated['assignee_type'] ?? 'user';

        if ($assigneeId) {
            $client = $this->portalClient($request);

            $exists = match ($assigneeType) {
                'client_contact' => ClientContact::where('id', $assigneeId)
                    ->where('client_id', $client->id)
                    ->exists(),
                default => User::where('id', $assigneeId)->exists(),
            };

            if (! $exists) {
                return response()->json(['error' => 'The selected assignee does not exist.'], 422);
            }
        }

        $oldAssigneeInfo = $task->assignee_info;

        $task->update([
            'assigned_to' => $assigneeId,
            'assignee_type' => $assigneeId ? $assigneeType : null,
        ]);

        if ($request->user()) {
            TaskComment::logAssignment(
                $task,
                $request->user()->id,
                $oldAssigneeInfo['id'] ?? null,
                $assigneeId,
                $oldAssigneeInfo['type'] ?? 'user',
                $assigneeType
            );
        }

        return response()->json([
            'assignee' => $this->sanitizeAssignee($task->fresh()->assignee_info),
        ]);
    }

    /**
     * Search for mentionable people (team members + client contacts).
     */
    public function mentionSearch(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        if (strlen($query) < 1) {
            return response()->json(['items' => []]);
        }

        $client = $this->portalClient($request);
        $items = collect();

        // Search internal team members
        $users = User::whereIn('role', ['owner', 'admin', 'staff'])
            ->where('name', 'like', "%{$query}%")
            ->limit(10)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'label' => $u->name,
                'type' => 'user',
                'url' => '#',
            ]);
        $items = $items->concat($users);

        // Search client contacts
        $contacts = ClientContact::where('client_id', $client->id)
            ->where('name', 'like', "%{$query}%")
            ->whereNotIn('email', User::pluck('email'))
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->name,
                'type' => 'user',
                'url' => '#',
            ]);
        $items = $items->concat($contacts);

        $items = $items->sortBy(function ($item) use ($query) {
            $label = strtolower($item['label']);
            $q = strtolower($query);
            if ($label === $q) {
                return 0;
            }

            return str_starts_with($label, $q) ? 1 : 2;
        })->take(10)->values();

        return response()->json(['items' => $items]);
    }

    /**
     * Mask agent assignees as "Zao Team" for client view.
     *
     * @param  array<string, mixed>|null  $assignee
     * @return array<string, mixed>|null
     */
    private function sanitizeAssignee(?array $assignee): ?array
    {
        if ($assignee && $assignee['type'] === 'agent') {
            return ['id' => null, 'name' => 'Zao Team', 'type' => 'user'];
        }

        return $assignee;
    }
}
