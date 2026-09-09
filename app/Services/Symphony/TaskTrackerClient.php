<?php

namespace App\Services\Symphony;

use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaskTrackerClient
{
    /**
     * @param  array<int, string>  $activeStates
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchCandidateIssues(array $activeStates): Collection
    {
        $normalizedStates = collect($activeStates)
            ->map(fn (string $state) => Str::lower(trim($state)))
            ->values()
            ->all();

        return Task::query()
            ->where('assignee_type', 'agent')
            ->whereNotNull('assigned_to')
            ->whereIn('status', $normalizedStates)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Task $task) => $this->toIssue($task));
    }

    /**
     * @param  array<int, int|string>  $taskIds
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchIssueStatesByIds(array $taskIds): Collection
    {
        $ids = collect($taskIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->map(fn (Task $task) => $this->toIssue($task));
    }

    /**
     * @param  array<int, string>  $terminalStates
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchTerminalIssues(array $terminalStates): Collection
    {
        $normalizedStates = collect($terminalStates)
            ->map(fn (string $state) => Str::lower(trim($state)))
            ->values()
            ->all();

        return Task::query()
            ->whereIn('status', $normalizedStates)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn (Task $task) => $this->toIssue($task));
    }

    /**
     * @return array<string, mixed>
     */
    public function toIssue(Task $task): array
    {
        $labels = collect($task->metadata['labels'] ?? [])
            ->filter(fn ($label) => is_string($label))
            ->map(fn ($label) => Str::lower(trim($label)))
            ->values()
            ->all();

        $blockedBy = collect($task->metadata['blocked_by'] ?? [])
            ->filter(fn ($blocker) => is_array($blocker))
            ->map(function (array $blocker): array {
                return [
                    'id' => $blocker['id'] ?? null,
                    'identifier' => $blocker['identifier'] ?? null,
                    'state' => isset($blocker['state']) ? Str::lower((string) $blocker['state']) : null,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (string) $task->id,
            'identifier' => 'TASK-'.$task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $this->normalizePriority($task->priority),
            'state' => Str::lower($task->status),
            'branch_name' => $task->metadata['preview_branch'] ?? null,
            'url' => route('api.tasks.show', $task),
            'labels' => $labels,
            'blocked_by' => $blockedBy,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    protected function normalizePriority(?string $priority): ?int
    {
        return match ($priority) {
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => null,
        };
    }
}
