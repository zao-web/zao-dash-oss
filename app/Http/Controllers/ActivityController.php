<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Services\Activity\ClientActivityService;
use App\Services\Activity\PersistActivityAsTasks;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function show(Client $client): Response
    {
        return $this->renderForClient($client, null);
    }

    public function showForProject(Client $client, string $projectSlug): Response
    {
        $project = Project::query()
            ->where('client_id', $client->id)
            ->where('slug', $projectSlug)
            ->firstOrFail();

        return $this->renderForClient($client, $project);
    }

    public function refresh(Client $client, ClientActivityService $synth, PersistActivityAsTasks $persist): RedirectResponse
    {
        $result = $synth->synthesize($client, force: true);
        $persist->persist($client, $result['items']);

        return back()->with('success', sprintf('Activity refreshed: %d items synthesised.', count($result['items'])));
    }

    protected function renderForClient(Client $client, ?Project $project): Response
    {
        $tasks = PersistActivityAsTasks::loadForClient($client);

        if ($project) {
            $tasks = $tasks->where('project_id', $project->id);
        }

        $statusOrder = [
            'not_started' => 0,
            'in_progress' => 1,
            'waiting_on_client' => 2,
            'completed_recently' => 3,
        ];

        $items = $tasks->map(function ($task) use ($statusOrder) {
            $primaryMapping = $task->externalMappings
                ->sortByDesc('last_synced_at')
                ->first();

            $clientStatus = $primaryMapping?->external_data['client_facing_status'] ?? 'in_progress';
            $clientSummary = $primaryMapping?->external_data['client_summary'] ?? $task->description;
            $firstRaisedAt = $primaryMapping?->external_data['first_raised_at'] ?? null;
            $lastActivityAt = $primaryMapping?->external_data['last_activity_at'] ?? null;

            $sources = $task->externalMappings->map(fn ($m) => [
                'type' => $m->source?->type,
                'external_id' => $m->external_id,
                'url' => $m->external_url,
            ])->values();

            return [
                'id' => $task->id,
                'title' => $task->title,
                'client_summary' => $clientSummary,
                'client_status' => $clientStatus,
                'internal_status' => $task->status,
                'first_raised_at' => $firstRaisedAt,
                'last_activity_at' => $lastActivityAt,
                'sources' => $sources,
                'project' => $task->project ? [
                    'id' => $task->project->id,
                    'name' => $task->project->name,
                    'slug' => $task->project->slug,
                ] : null,
                '_sort' => $statusOrder[$clientStatus] ?? 99,
            ];
        })
            ->sortBy('_sort')
            ->values()
            ->map(function ($item) {
                unset($item['_sort']);

                return $item;
            });

        return Inertia::render('Activity/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
            ],
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ] : null,
            'items' => $items,
            'generated_at' => optional($tasks->first()?->externalMappings->max('last_synced_at'))?->toIso8601String(),
        ]);
    }
}
