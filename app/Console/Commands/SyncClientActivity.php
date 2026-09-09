<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Services\Activity\ClientActivityService;
use App\Services\Activity\PersistActivityAsTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncClientActivity extends Command
{
    protected $signature = 'activity:sync
        {--client= : Sync only the client with this id or slug}
        {--force : Bypass the 30-min synthesis cache and the per-client debounce}';

    protected $description = 'Synthesize each active retainer client\'s current activity into Task rows for the Activity view, Tasks tab, and retainer reports.';

    public function handle(ClientActivityService $synth, PersistActivityAsTasks $persist): int
    {
        $clients = $this->resolveClients();

        if ($clients->isEmpty()) {
            $this->warn('No matching active-retainer clients to sync.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($clients as $client) {
            $debounceKey = sprintf('activity.sync.debounce.%d', $client->id);
            if (! $force && Cache::get($debounceKey)) {
                $this->line("· {$client->name}: skipped (debounced within last 30 min)");

                continue;
            }

            $result = $synth->synthesize($client, $force);
            if (! empty($result['warnings'])) {
                $this->warn("⚠ {$client->name}: ".implode('; ', $result['warnings']));
            }

            $stats = $persist->persist($client, $result['items']);
            Cache::put($debounceKey, true, now()->addMinutes(30));

            $totalCreated += $stats['created'];
            $totalUpdated += $stats['updated'];
            $totalSkipped += $stats['skipped_conflict'];

            $this->info(sprintf(
                '✓ %s: %d items synthesised → created %d, updated %d, skipped (manual edit) %d',
                $client->name,
                count($result['items']),
                $stats['created'],
                $stats['updated'],
                $stats['skipped_conflict'],
            ));
        }

        $this->line('');
        $this->info(sprintf('Done. Across %d client(s): %d created, %d updated, %d skipped.', $clients->count(), $totalCreated, $totalUpdated, $totalSkipped));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Client>
     */
    protected function resolveClients(): \Illuminate\Database\Eloquent\Collection
    {
        if ($filter = $this->option('client')) {
            // Postgres refuses to coerce a non-numeric string to bigint, so
            // only probe id when the filter is numeric. Slug otherwise.
            $client = Client::query()
                ->when(ctype_digit((string) $filter), fn ($q) => $q->where('id', $filter))
                ->when(! ctype_digit((string) $filter), fn ($q) => $q->where('slug', $filter))
                ->first();

            return $client
                ? new \Illuminate\Database\Eloquent\Collection([$client])
                : new \Illuminate\Database\Eloquent\Collection;
        }

        $clientIds = RetainerPeriod::query()
            ->active()
            ->where('period_start', '<=', now())
            ->where('period_end', '>=', now())
            ->pluck('client_id')
            ->unique()
            ->values();

        return Client::query()->whereIn('id', $clientIds)->get();
    }
}
