<?php

namespace App\Services\Slack;

use App\Models\SlackChannel;
use App\Models\SlackUserWatchlistItem;
use App\Models\SlackWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SlackWatchlistService
{
    /**
     * @return Collection<int, SlackUserWatchlistItem>
     */
    public function listItems(SlackWorkspace $workspace, string $slackUserId): Collection
    {
        return SlackUserWatchlistItem::query()
            ->with(['channel.client', 'channel.project'])
            ->where('workspace_id', $workspace->id)
            ->where('slack_user_id', $slackUserId)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return array{success: bool, message: string, items: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>, ambiguous: array<int, array<string, mixed>>}
     */
    public function trackByQuery(SlackWorkspace $workspace, string $slackUserId, string $query): array
    {
        return $this->applyQuery($workspace, $slackUserId, $query, true);
    }

    /**
     * @return array{success: bool, message: string, items: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>, ambiguous: array<int, array<string, mixed>>}
     */
    public function untrackByQuery(SlackWorkspace $workspace, string $slackUserId, string $query): array
    {
        return $this->applyQuery($workspace, $slackUserId, $query, false);
    }

    /**
     * @return array{success: bool, message: string, items: array<int, array<string, mixed>>}
     */
    public function clear(SlackWorkspace $workspace, string $slackUserId): array
    {
        $items = SlackUserWatchlistItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('slack_user_id', $slackUserId)
            ->where('is_active', true)
            ->get();

        foreach ($items as $item) {
            $item->update(['is_active' => false, 'last_accessed_at' => now()]);
        }

        return [
            'success' => true,
            'message' => $items->isEmpty()
                ? 'Your watchlist is already empty.'
                : 'Cleared your Slack watchlist.',
            'items' => $items->map(fn (SlackUserWatchlistItem $item): array => $this->formatItem($item))->values()->all(),
        ];
    }

    /**
     * @return array{success: bool, message: string, items: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>, ambiguous: array<int, array<string, mixed>>}
     */
    protected function applyQuery(SlackWorkspace $workspace, string $slackUserId, string $query, bool $track): array
    {
        $parts = $this->splitQueries($query);
        $items = [];
        $unresolved = [];
        $ambiguous = [];

        foreach ($parts as $part) {
            $matches = $this->resolveCandidates($workspace, $part, $track);

            if ($matches->isEmpty()) {
                $unresolved[] = [
                    'query' => $part,
                ];

                continue;
            }

            if ($matches->count() > 1) {
                $ambiguous[] = [
                    'query' => $part,
                    'candidates' => $matches->take(5)->map(fn (SlackChannel $channel): array => $this->formatCandidate($channel))->values()->all(),
                ];

                continue;
            }

            $channel = $matches->first();
            $item = SlackUserWatchlistItem::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'slack_user_id' => $slackUserId,
                    'slack_channel_id' => $channel->id,
                ],
                [
                    'label' => $this->deriveLabel($part, $channel),
                    'source' => 'manual',
                    'is_active' => $track,
                    'last_accessed_at' => now(),
                ]
            );

            if (! $track) {
                $item->update([
                    'is_active' => false,
                    'last_accessed_at' => now(),
                ]);
            }

            $items[] = $this->formatItem($item->fresh(['channel.client', 'channel.project']));
        }

        $message = $this->buildSummaryMessage($track, count($items), count($unresolved), count($ambiguous));

        return [
            'success' => true,
            'message' => $message,
            'items' => $items,
            'unresolved' => $unresolved,
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @return Collection<int, SlackChannel>
     */
    protected function resolveCandidates(SlackWorkspace $workspace, string $query, bool $requireMonitoring = true): Collection
    {
        $normalized = Str::of($query)->trim()->lower()->replaceMatches('/^#/', '')->toString();

        if ($normalized === '') {
            return collect();
        }

        $exactChannel = SlackChannel::query()
            ->with(['client.projects', 'project'])
            ->where('workspace_id', $workspace->id)
            ->when($requireMonitoring, fn ($query) => $query->where('monitoring_enabled', true))
            ->whereRaw('LOWER(channel_name) = ?', [$normalized])
            ->get();

        if ($exactChannel->isNotEmpty()) {
            return $exactChannel;
        }

        $channels = SlackChannel::query()
            ->with(['client.projects', 'project'])
            ->where('workspace_id', $workspace->id)
            ->when($requireMonitoring, fn ($query) => $query->where('monitoring_enabled', true))
            ->where(function ($queryBuilder) use ($normalized): void {
                $queryBuilder->whereRaw('LOWER(channel_name) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%'])
                    ->orWhereHas('client', function ($clientQuery) use ($normalized): void {
                        $clientQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%']);
                    })
                    ->orWhereHas('project', function ($projectQuery) use ($normalized): void {
                        $projectQuery->whereRaw('LOWER(name) LIKE ?', ['%'.$normalized.'%']);
                    });
            })
            ->get();

        return $channels->sortByDesc(function (SlackChannel $channel) use ($normalized): int {
            $score = 0;

            foreach (array_filter([
                $channel->channel_name,
                $channel->name,
                $channel->client?->name,
                $channel->project?->name,
            ]) as $value) {
                $value = Str::of((string) $value)->lower()->trim()->toString();

                if ($value === $normalized) {
                    $score = max($score, 100);
                } elseif (str_contains($value, $normalized) || str_contains($normalized, $value)) {
                    $score = max($score, 80);
                }
            }

            if ($channel->classification === 'client') {
                $score += 10;
            }

            return $score;
        })->values();
    }

    /**
     * @return array<int, string>
     */
    protected function splitQueries(string $query): array
    {
        $normalized = trim($query);

        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|\band\b|&)\s*/i', $normalized) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim($part, " \t\n\r\0\x0B.,"))
            ->filter()
            ->values()
            ->all();
    }

    protected function deriveLabel(string $query, SlackChannel $channel): string
    {
        $label = trim($query);

        if ($label === '') {
            return $channel->channel_name;
        }

        return $label;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatItem(SlackUserWatchlistItem $item): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'channel_id' => $item->channel?->channel_id,
            'channel_name' => $item->channel?->channel_name,
            'classification' => $item->channel?->classification,
            'monitoring_enabled' => (bool) $item->channel?->monitoring_enabled,
            'client' => $item->channel?->client ? [
                'id' => $item->channel->client->id,
                'name' => $item->channel->client->name,
            ] : null,
            'project' => $item->channel?->project ? [
                'id' => $item->channel->project->id,
                'name' => $item->channel->project->name,
                'status' => $item->channel->project->status,
            ] : null,
            'last_accessed_at' => $item->last_accessed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCandidate(SlackChannel $channel): array
    {
        return [
            'channel_id' => $channel->channel_id,
            'channel_name' => $channel->channel_name,
            'label' => $channel->name ?? $channel->channel_name,
            'client' => $channel->client ? [
                'id' => $channel->client->id,
                'name' => $channel->client->name,
            ] : null,
            'project' => $channel->project ? [
                'id' => $channel->project->id,
                'name' => $channel->project->name,
            ] : null,
        ];
    }

    protected function buildSummaryMessage(bool $track, int $trackedCount, int $unresolvedCount, int $ambiguousCount): string
    {
        $verb = $track ? 'Tracked' : 'Untracked';

        $message = $trackedCount > 0
            ? "{$verb} {$trackedCount} channel".($trackedCount === 1 ? '' : 's').'.'
            : ($track ? 'No channels were added to your watchlist.' : 'No channels were removed from your watchlist.');

        $notes = [];

        if ($unresolvedCount > 0) {
            $notes[] = "{$unresolvedCount} could not be resolved";
        }

        if ($ambiguousCount > 0) {
            $notes[] = "{$ambiguousCount} need disambiguation";
        }

        if ($notes !== []) {
            $message .= ' '.implode(', ', $notes).'.';
        }

        return $message;
    }
}
