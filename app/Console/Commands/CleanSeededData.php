<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanSeededData extends Command
{
    protected $signature = 'db:clean-seeded {--force : Skip confirmation prompt}';

    protected $description = 'Remove all seeded/demo data for production go-live';

    /**
     * Tables to clean, in order (respecting foreign key constraints).
     */
    protected array $tables = [
        'agent_runs',
        'vault_secrets',
        'milestones',
        'tasks',
        'projects',
        'client_contacts',
        'clients',
        'contractor_invoices',
        'contractors',
        'leads',
        'agents',
    ];

    public function handle(): int
    {
        // Show preview
        $this->info('Seeded data to be deleted:');
        $this->newLine();

        $totalRecords = 0;
        $counts = [];

        foreach ($this->tables as $table) {
            $count = DB::table($table)->whereNotNull('seeded_at')->count();
            $counts[$table] = $count;
            $totalRecords += $count;

            if ($count > 0) {
                $this->line("  {$table}: {$count} records");
            }
        }

        $this->newLine();
        $this->warn("Total: {$totalRecords} records will be deleted.");
        $this->newLine();

        if ($totalRecords === 0) {
            $this->info('No seeded data found. Database is already clean.');

            return 0;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('This will DELETE all seeded data. Continue?', false)) {
                $this->info('Aborted.');

                return 0;
            }
        }

        $this->info('Cleaning seeded data...');
        $this->newLine();

        DB::transaction(function () use ($counts) {
            foreach ($this->tables as $table) {
                if ($counts[$table] > 0) {
                    $deleted = DB::table($table)
                        ->whereNotNull('seeded_at')
                        ->delete();

                    $this->line("  Deleted {$deleted} records from {$table}");
                }
            }
        });

        $this->newLine();
        $this->info('✓ Seeded data cleaned. Ready for production.');

        return 0;
    }
}
