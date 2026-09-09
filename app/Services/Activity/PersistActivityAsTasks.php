<?php

namespace App\Services\Activity;

use App\Models\Client;
use App\Models\ExternalTaskMapping;
use App\Models\ExternalTaskSource;
use App\Models\GitHubRepo;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns synthesised activity items into Task rows, using the existing
 * ExternalTaskMapping machinery for dedup across runs. Designed to be
 * called immediately after ClientActivityService::synthesize() — never
 * creates duplicate Tasks for the same underlying work, and respects
 * manual edits (won't clobber a Task a human just changed).
 */
class PersistActivityAsTasks
{
    /**
     * Map from client-facing status (LLM output) to the internal Task
     * status enum. The richer status is preserved on each mapping's
     * external_data for the Activity view to read back.
     */
    public const STATUS_MAP = [
        'not_started' => 'pending',
        'in_progress' => 'in_progress',
        'waiting_on_client' => 'review',
        'completed_recently' => 'completed',
    ];

    /**
     * @param  array<int, array{title:string, client_summary:string, status:string, first_raised_at:?string, last_activity_at:?string, evidence:array<int,string>, external_ids:array<int,array{source:string, id:string}>}>  $items
     * @return array{created:int, updated:int, skipped_conflict:int}
     */
    public function persist(Client $client, array $items): array
    {
        if (empty($items)) {
            return ['created' => 0, 'updated' => 0, 'skipped_conflict' => 0];
        }

        $sourcesByType = $this->resolveSources($client);
        $stats = ['created' => 0, 'updated' => 0, 'skipped_conflict' => 0];

        foreach ($items as $item) {
            DB::transaction(function () use ($client, $item, $sourcesByType, &$stats): void {
                $existing = $this->findExistingMapping($item['external_ids']);

                if ($existing) {
                    $task = $existing->task;
                    if (! $task) {
                        return;
                    }

                    if ($this->hasManualEditSinceLastSync($task, $existing)) {
                        $stats['skipped_conflict']++;
                        // Still refresh mapping data so the Activity view sees
                        // the latest synthesised summary; just don't touch the
                        // Task itself.
                        $this->upsertMappings($task, $client, $item, $sourcesByType);

                        return;
                    }

                    $updates = [
                        'title' => $item['title'],
                        'description' => $item['client_summary'],
                        'status' => self::STATUS_MAP[$item['status']] ?? 'pending',
                    ];
                    // If a previous run created this task without a project_id
                    // (e.g. Slack-only signal before channel→project routing
                    // existed), retroactively assign one now if we can.
                    if (! $task->project_id) {
                        $inferred = $this->inferProjectId($client, $item);
                        if ($inferred) {
                            $updates['project_id'] = $inferred;
                        }
                    }
                    $task->update($updates);

                    $this->upsertMappings($task, $client, $item, $sourcesByType);
                    $stats['updated']++;

                    return;
                }

                $task = Task::create([
                    'title' => $item['title'],
                    'description' => $item['client_summary'],
                    'status' => self::STATUS_MAP[$item['status']] ?? 'pending',
                    'priority' => 'medium',
                    'source' => 'activity-feed',
                    'project_id' => $this->inferProjectId($client, $item),
                ]);

                $this->upsertMappings($task, $client, $item, $sourcesByType);
                $stats['created']++;
            });
        }

        return $stats;
    }

    /**
     * Find the first existing Task that any of this item's external_ids
     * already point at. We check sources in priority order so an internal
     * task wins over a slack message (the human-created Task is the
     * canonical record).
     */
    protected function findExistingMapping(array $externalIds): ?ExternalTaskMapping
    {
        $priority = ['internal_task', 'github_pr', 'github_issue', 'email', 'slack'];

        $sorted = collect($externalIds)
            ->sortBy(fn ($ref) => array_search($ref['source'], $priority, true) ?: 99)
            ->values();

        foreach ($sorted as $ref) {
            $mapping = ExternalTaskMapping::query()
                ->whereHas('source', fn ($q) => $q->where('type', $ref['source']))
                ->where('external_id', $ref['id'])
                ->with('task')
                ->first();

            if ($mapping) {
                return $mapping;
            }
        }

        // Special-case the internal_task pointer — it directly names a task ID
        // without needing a mapping row, so resolve it as a synthetic mapping.
        foreach ($externalIds as $ref) {
            if ($ref['source'] === 'internal_task' && str_starts_with($ref['id'], 'task:')) {
                $taskId = (int) substr($ref['id'], 5);
                $task = Task::find($taskId);
                if ($task) {
                    // Wrap the task in a transient mapping-shaped value so the
                    // caller code stays uniform. Persist a real mapping in the
                    // upsert step so future runs find it the fast way.
                    $synthetic = new ExternalTaskMapping;
                    $synthetic->setRelation('task', $task);

                    return $synthetic;
                }
            }
        }

        return null;
    }

    protected function hasManualEditSinceLastSync(Task $task, ExternalTaskMapping $mapping): bool
    {
        if (! $mapping->exists || ! $mapping->last_synced_at) {
            return false;
        }

        return $task->updated_at->gt($mapping->last_synced_at);
    }

    /**
     * Upsert ExternalTaskMapping rows for every external_id on the item,
     * keyed on (source_type, external_id). external_data carries the
     * client-facing status + summary so the Activity view can render
     * without re-running the LLM.
     */
    protected function upsertMappings(Task $task, Client $client, array $item, array $sourcesByType): void
    {
        foreach ($item['external_ids'] as $ref) {
            $source = $sourcesByType[$ref['source']] ?? null;
            if (! $source) {
                continue;
            }

            ExternalTaskMapping::updateOrCreate(
                [
                    'external_task_source_id' => $source->id,
                    'external_id' => $ref['id'],
                ],
                [
                    'task_id' => $task->id,
                    'external_url' => $this->permalinkFor($ref),
                    'external_data' => [
                        'client_facing_status' => $item['status'],
                        'client_summary' => $item['client_summary'],
                        'first_raised_at' => $item['first_raised_at'],
                        'last_activity_at' => $item['last_activity_at'],
                        'evidence' => $item['evidence'],
                        'all_external_ids' => $item['external_ids'],
                        'client_id' => $client->id,
                    ],
                    'sync_status' => ExternalTaskMapping::STATUS_SYNCED,
                    'sync_direction' => ExternalTaskMapping::DIRECTION_INBOUND,
                    'last_synced_at' => now(),
                ]
            );
        }
    }

    protected function permalinkFor(array $ref): ?string
    {
        return match ($ref['source']) {
            'github_pr' => str_contains($ref['id'], ':')
                ? 'https://github.com/'.str_replace(':', '/pull/', $ref['id'])
                : null,
            'github_issue' => str_contains($ref['id'], ':i:')
                ? 'https://github.com/'.str_replace(':i:', '/issues/', $ref['id'])
                : null,
            default => null,
        };
    }

    /**
     * Find-or-create the ExternalTaskSource row for each activity-feed type
     * for this client. Returns a map [type => ExternalTaskSource].
     *
     * @return array<string, ExternalTaskSource>
     */
    protected function resolveSources(Client $client): array
    {
        $byType = [];
        foreach (ExternalTaskSource::activityFeedTypes() as $type) {
            $source = ExternalTaskSource::query()
                ->where('client_id', $client->id)
                ->where('type', $type)
                ->whereNull('pm_connection_id')
                ->first();

            if (! $source) {
                $source = ExternalTaskSource::create([
                    'pm_connection_id' => null,
                    'client_id' => $client->id,
                    'external_id' => sprintf('activity:%d:%s', $client->id, $type),
                    'name' => $client->name.' — '.$type,
                    'type' => $type,
                    'auto_import' => true,
                    'sync_back' => false,
                ]);
            }

            $byType[$type] = $source;
        }

        return $byType;
    }

    /**
     * Pick the best project_id for a new task based on the item's evidence.
     *
     * Resolution order:
     *   1. Internal task pointer → that task's project_id
     *   2. GitHub PR/issue → repo's linked project_id
     *   3. Slack signal → channel's linked project (via slack_channels.id
     *      = projects.slack_channel_id)
     *   4. Client's single active project, if exactly one exists
     *   5. Client's most-recently-created project, if any
     *   6. null (client-scoped — invisible on project Tasks tabs)
     *
     * Falling back to the client's primary project keeps Slack-only items
     * from disappearing into a client-scoped void no one looks at.
     */
    protected function inferProjectId(Client $client, array $item): ?int
    {
        foreach ($item['external_ids'] as $ref) {
            if ($ref['source'] === 'internal_task' && str_starts_with($ref['id'], 'task:')) {
                $taskId = (int) substr($ref['id'], 5);
                $projectId = Task::query()->whereKey($taskId)->value('project_id');
                if ($projectId) {
                    return $projectId;
                }
            }
        }

        foreach ($item['external_ids'] as $ref) {
            if (in_array($ref['source'], ['github_pr', 'github_issue'], true)) {
                $repoFullName = explode(':', $ref['id'])[0] ?? null;
                if ($repoFullName) {
                    $projectId = GitHubRepo::query()
                        ->where('full_name', $repoFullName)
                        ->value('project_id');
                    if ($projectId) {
                        return $projectId;
                    }
                }
            }
        }

        foreach ($item['external_ids'] as $ref) {
            if ($ref['source'] === 'slack') {
                $channelId = explode(':', $ref['id'])[0] ?? null;
                if ($channelId) {
                    $channel = SlackChannel::query()->where('channel_id', $channelId)->first();
                    if ($channel) {
                        $projectId = Project::query()
                            ->where('slack_channel_id', $channel->id)
                            ->value('id');
                        if ($projectId) {
                            return $projectId;
                        }
                    }
                }
            }
        }

        // Fallback: pin to the client's primary project so the task lands
        // somewhere visible. Prefer active over inactive when multiple exist.
        $primaryProjectId = Project::query()
            ->where('client_id', $client->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->value('id');

        return $primaryProjectId;
    }

    /**
     * Convenience: fetch every activity-feed Task for a client along with
     * its mapping payload, ordered for display.
     */
    public static function loadForClient(Client $client): Collection
    {
        return Task::query()
            ->where('source', 'activity-feed')
            ->whereHas('externalMappings.source', fn ($q) => $q->where('client_id', $client->id))
            ->with(['externalMappings.source', 'project:id,name,slug'])
            ->orderByDesc('updated_at')
            ->get();
    }
}
