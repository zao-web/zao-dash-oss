<?php

namespace App\Services\Activity\Signals;

use App\Models\Client;
use App\Models\Task;
use Carbon\Carbon;

class InternalTaskSignalCollector
{
    /**
     * Collect existing internal tasks (pending/in_progress/review) for the
     * client's projects. These flow into synthesis so the model can merge
     * them with raw signals when they describe the same underlying work.
     *
     * @return array<int, array{source_type:string, external_id:string, permalink:?string, occurred_at:Carbon, actor:?string, content:string, meta:array<string,mixed>}>
     */
    public function collect(Client $client, Carbon $since): array
    {
        $tasks = Task::query()
            ->whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->whereIn('status', ['pending', 'in_progress', 'review'])
            ->with('project:id,name,slug,client_id')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return $tasks->map(function (Task $task): array {
            return [
                'source_type' => 'internal_task',
                'external_id' => 'task:'.$task->id,
                'permalink' => null,
                'occurred_at' => $task->updated_at ?? $task->created_at,
                'actor' => null,
                'content' => trim($task->title."\n\n".(string) $task->description),
                'meta' => [
                    'task_id' => $task->id,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'source' => $task->source,
                    'project_id' => $task->project_id,
                    'project_name' => $task->project?->name,
                    'due_date' => $task->due_date?->toDateString(),
                ],
            ];
        })->all();
    }
}
