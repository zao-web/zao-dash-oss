<?php

namespace App\Console\Commands;

use App\Agents\AgentRegistry;
use Illuminate\Console\Command;

class SyncAgentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agents:sync
        {--dry-run : Show what would be synced without making changes}
        {--force : Force sync even if agents appear unchanged}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync PHP agent definitions to the database';

    /**
     * Execute the console command.
     */
    public function handle(AgentRegistry $registry): int
    {
        $this->info('Discovering agent definitions...');
        $this->newLine();

        $definitions = $registry->allDefinitions();

        if ($definitions->isEmpty()) {
            $this->warn('No agent definitions found in app/Agents/Definitions/');

            return self::SUCCESS;
        }

        $this->info("Found {$definitions->count()} agent definition(s):");
        $this->newLine();

        $headers = ['ID', 'Name', 'Model', 'Trigger', 'Approval', 'Budget'];
        $rows = [];

        foreach ($definitions as $definition) {
            $meta = $definition->metadata();
            $rows[] = [
                $meta['id'],
                $meta['name'],
                $meta['model'],
                $meta['trigger'] ?? 'manual',
                $meta['requires_approval'] ? 'Yes' : 'No',
                '$'.number_format($meta['max_budget_usd'], 2),
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('Dry run mode - no changes made');

            return self::SUCCESS;
        }

        $this->info('Syncing to database...');

        $stats = $registry->syncToDatabase();

        $this->newLine();
        $this->info('Sync complete:');
        $this->line("  <fg=green>Created:</> {$stats['created']}");
        $this->line("  <fg=yellow>Updated:</> {$stats['updated']}");
        $this->line("  <fg=gray>Unchanged:</> {$stats['unchanged']}");

        // Check for out of sync agents
        $outOfSync = $registry->getOutOfSyncAgents();
        if ($outOfSync->isNotEmpty()) {
            $this->newLine();
            $this->warn("Warning: {$outOfSync->count()} agent(s) may be out of sync:");
            foreach ($outOfSync as $agent) {
                $this->line("  - {$agent->name} ({$agent->slug})");
            }
        }

        return self::SUCCESS;
    }
}
