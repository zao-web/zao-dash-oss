<?php

namespace App\Console\Commands;

use App\Agents\AgentRegistry;
use Illuminate\Console\Command;

class SyncAgents extends Command
{
    protected $signature = 'agents:sync
                            {--force : Force sync even if unchanged}
                            {--list : List all discovered agent definitions}';

    protected $description = 'Sync agent definitions from code to database';

    public function handle(AgentRegistry $registry): int
    {
        if ($this->option('list')) {
            $this->listAgents($registry);

            return self::SUCCESS;
        }

        $this->info('Syncing agent definitions...');

        $stats = $registry->syncToDatabase();

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Unchanged', $stats['unchanged']],
            ]
        );

        $total = array_sum($stats);
        $this->newLine();
        $this->info("Synced {$total} agent(s) successfully.");

        // Show any out-of-sync agents
        $outOfSync = $registry->getOutOfSyncAgents();
        if ($outOfSync->isNotEmpty()) {
            $this->newLine();
            $this->warn("⚠ Found {$outOfSync->count()} agent(s) still out of sync:");
            foreach ($outOfSync as $agent) {
                $this->line("  - {$agent->name} ({$agent->slug})");
            }
        }

        return self::SUCCESS;
    }

    protected function listAgents(AgentRegistry $registry): void
    {
        $definitions = $registry->allDefinitions();

        $this->info("Found {$definitions->count()} agent definition(s):");
        $this->newLine();

        $rows = [];
        foreach ($definitions as $id => $definition) {
            $meta = $definition->metadata();
            $rows[] = [
                $id,
                $meta['name'],
                $meta['model'],
                $meta['requires_approval'] ? 'Yes' : 'No',
                '$'.number_format($meta['max_budget_usd'], 2),
                count($definition->allowedTools()),
            ];
        }

        $this->table(
            ['ID', 'Name', 'Model', 'Requires Approval', 'Max Budget', 'Tools'],
            $rows
        );
    }
}
