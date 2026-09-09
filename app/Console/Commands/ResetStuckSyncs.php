<?php

namespace App\Console\Commands;

use App\Models\GoogleCredential;
use App\Models\HarvestCredential;
use App\Models\NotionConnection;
use App\Models\QuickBooksConnection;
use App\Models\SlackWorkspace;
use Illuminate\Console\Command;

class ResetStuckSyncs extends Command
{
    protected $signature = 'sync:reset-stuck {--all : Reset all syncs regardless of status}';

    protected $description = 'Reset stuck sync jobs (status=syncing for > 30 minutes)';

    public function handle(): int
    {
        $stuckThreshold = now()->subMinutes(30);
        $resetAll = $this->option('all');

        $connections = [
            'Google' => GoogleCredential::class,
            'Harvest' => HarvestCredential::class,
            'Notion' => NotionConnection::class,
            'QuickBooks' => QuickBooksConnection::class,
            'Slack' => SlackWorkspace::class,
        ];

        $totalReset = 0;

        foreach ($connections as $name => $class) {
            $query = $class::query();

            if ($resetAll) {
                $query->whereIn('sync_status', ['syncing', 'failed']);
            } else {
                $query->where('sync_status', 'syncing')
                    ->where('sync_started_at', '<', $stuckThreshold);
            }

            $stuck = $query->get();

            foreach ($stuck as $connection) {
                $connection->update([
                    'sync_status' => 'pending',
                    'sync_progress' => 0,
                    'sync_error' => null,
                ]);
                $totalReset++;
                $this->line("Reset {$name} sync (ID: {$connection->id})");
            }
        }

        if ($totalReset === 0) {
            $this->info('No stuck syncs found.');
        } else {
            $this->info("Reset {$totalReset} stuck sync(s). Re-run syncs from Integrations page.");
        }

        return 0;
    }
}
