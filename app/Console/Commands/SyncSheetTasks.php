<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientSheetSync;
use App\Models\User;
use App\Services\GoogleSheets\SheetTaskSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncSheetTasks extends Command
{
    protected $signature = 'sheets:sync
        {--client= : Sync only the sheets attached to this client id or slug}
        {--force : Bypass the per-sheet debounce}';

    protected $description = 'Bidirectionally sync configured client task-tracker Google Sheets into Task rows and back.';

    public function handle(SheetTaskSyncService $sync): int
    {
        $user = User::query()->where('role', 'owner')->orderBy('id')->first();
        if (! $user) {
            $this->error('No owner-role user available to act as Google credential holder.');

            return self::FAILURE;
        }

        $configs = $this->resolveConfigs();
        if ($configs->isEmpty()) {
            $this->warn('No active client sheet syncs match.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        foreach ($configs as $config) {
            $debounceKey = sprintf('sheets.sync.debounce.%d', $config->id);
            if (! $force && Cache::get($debounceKey)) {
                $this->line("· config #{$config->id}: skipped (debounced)");

                continue;
            }

            try {
                $stats = $sync->sync($config, $user);
                Cache::put($debounceKey, true, now()->addMinutes(10));
                $this->info(sprintf(
                    '✓ config #%d (client %d, sheet %s): rows=%d ids+=%d created=%d updated=%d sheet_writes=%d conflict=%d',
                    $config->id,
                    $config->client_id,
                    $config->spreadsheet_id,
                    $stats['rows_seen'],
                    $stats['ids_assigned'],
                    $stats['tasks_created'],
                    $stats['tasks_updated'],
                    $stats['sheet_writes'],
                    $stats['skipped_conflict'],
                ));
            } catch (\Throwable $e) {
                $this->error(sprintf('✗ config #%d failed: %s', $config->id, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ClientSheetSync>
     */
    protected function resolveConfigs(): \Illuminate\Database\Eloquent\Collection
    {
        $query = ClientSheetSync::query()->where('active', true);

        if ($filter = $this->option('client')) {
            $clientId = ctype_digit((string) $filter)
                ? (int) $filter
                : Client::query()->where('slug', $filter)->value('id');

            if (! $clientId) {
                return new \Illuminate\Database\Eloquent\Collection;
            }
            $query->where('client_id', $clientId);
        }

        return $query->get();
    }
}
