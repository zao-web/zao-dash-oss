<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\HarvestCredential;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\HarvestTaskCategory;
use App\Models\TimeEntry;
use App\Services\Harvest\HarvestService;
use App\Services\Harvest\HarvestSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class HarvestFullImportCommand extends Command
{
    protected $signature = 'harvest:full-import
                            {--from= : Start date for time entries/invoices (default: 2015-01-01)}
                            {--dry-run : Show what would be imported without importing}
                            {--time-only : Only import time entries}
                            {--invoices-only : Only import invoices}';

    protected $description = 'Import ALL historical data from Harvest (time entries, invoices, projects, clients)';

    public function handle(HarvestService $harvestService, HarvestSyncService $syncService): int
    {
        $credential = HarvestCredential::where('is_active', true)->first();

        if (! $credential) {
            $this->error('No active Harvest credential found. Please connect Harvest first.');

            return Command::FAILURE;
        }

        $fromDate = Carbon::parse($this->option('from') ?? '2015-01-01');
        $dryRun = $this->option('dry-run');
        $timeOnly = $this->option('time-only');
        $invoicesOnly = $this->option('invoices-only');

        $this->info('=== Harvest Full Import ===');
        $this->info("Account: {$credential->account_name}");
        $this->info("Import from: {$fromDate->format('Y-m-d')}");
        $this->info('Dry run: '.($dryRun ? 'Yes' : 'No'));
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be imported');
            $this->newLine();
        }

        // Get current counts
        $this->showCurrentStats();

        if (! $timeOnly && ! $invoicesOnly) {
            // Sync clients first
            $this->info('📋 Syncing clients...');
            $clients = $harvestService->listClients($credential);
            $this->info('  Found '.count($clients).' clients in Harvest');

            if (! $dryRun) {
                $stats = $syncService->syncAllClientsFromHarvest($clients);
                $this->info("  Created: {$stats['created']}, Updated: {$stats['updated']}, Restored: {$stats['restored']}");
            }
            $this->newLine();

            // Sync task categories
            $this->info('📂 Syncing task categories...');
            $tasks = $harvestService->listTasks($credential);
            $this->info('  Found '.count($tasks).' task categories in Harvest');

            if (! $dryRun) {
                foreach ($tasks as $taskData) {
                    HarvestTaskCategory::updateOrCreate(
                        ['harvest_id' => $taskData['id']],
                        [
                            'name' => $taskData['name'],
                            'is_active' => $taskData['is_active'] ?? true,
                            'is_default' => $taskData['is_default'] ?? false,
                            'default_hourly_rate' => $taskData['default_hourly_rate'] ?? null,
                            'billable_by_default' => $taskData['billable_by_default'] ?? true,
                        ]
                    );
                }
            }
            $this->newLine();

            // Sync projects
            $this->info('📁 Syncing projects...');
            $projects = $harvestService->listProjects($credential);
            $this->info('  Found '.count($projects).' projects in Harvest');

            if (! $dryRun) {
                foreach ($projects as $projectData) {
                    HarvestProject::updateOrCreate(
                        ['harvest_id' => $projectData['id']],
                        [
                            'name' => $projectData['name'],
                            'code' => $projectData['code'] ?? null,
                            'client_name' => $projectData['client']['name'] ?? null,
                            'client_harvest_id' => $projectData['client']['id'] ?? null,
                            'is_active' => $projectData['is_active'] ?? true,
                            'is_billable' => $projectData['is_billable'] ?? true,
                            'is_fixed_fee' => $projectData['is_fixed_fee'] ?? false,
                            'bill_by' => $projectData['bill_by'] ?? 'none',
                            'budget' => $projectData['budget'] ?? null,
                            'budget_by' => $projectData['budget_by'] ?? 'none',
                            'budget_is_monthly' => $projectData['budget_is_monthly'] ?? false,
                            'hourly_rate' => $projectData['hourly_rate'] ?? null,
                            'cost_budget' => $projectData['cost_budget'] ?? null,
                            'fee' => $projectData['fee'] ?? null,
                            'notes' => $projectData['notes'] ?? null,
                            'starts_on' => isset($projectData['starts_on'])
                                ? Carbon::parse($projectData['starts_on'])
                                : null,
                            'ends_on' => isset($projectData['ends_on'])
                                ? Carbon::parse($projectData['ends_on'])
                                : null,
                        ]
                    );
                }
            }
            $this->newLine();
        }

        // Sync time entries (the big one)
        if (! $invoicesOnly) {
            $this->info('⏱️  Syncing ALL time entries since '.$fromDate->format('Y-m-d').'...');
            $this->info('   This may take a while...');

            $entries = $harvestService->listTimeEntries($credential, $fromDate);
            $this->info('  Found '.count($entries).' time entries in Harvest');

            if (! $dryRun) {
                $bar = $this->output->createProgressBar(count($entries));
                $bar->start();

                foreach ($entries as $entryData) {
                    $harvestProject = HarvestProject::where('harvest_id', $entryData['project']['id'])->first();
                    $taskCategory = HarvestTaskCategory::where('harvest_id', $entryData['task']['id'])->first();

                    TimeEntry::updateOrCreate(
                        ['harvest_id' => $entryData['id']],
                        [
                            'harvest_project_id' => $harvestProject?->id,
                            'task_category_id' => $taskCategory?->id,
                            'harvest_user_id' => $entryData['user']['id'] ?? null,
                            'harvest_user_name' => $entryData['user']['name'] ?? null,
                            'spent_date' => Carbon::parse($entryData['spent_date']),
                            'hours' => $entryData['hours'] ?? 0,
                            'rounded_hours' => $entryData['rounded_hours'] ?? $entryData['hours'] ?? 0,
                            'notes' => $entryData['notes'] ?? null,
                            'is_locked' => $entryData['is_locked'] ?? false,
                            'is_closed' => $entryData['is_closed'] ?? false,
                            'is_billed' => $entryData['is_billed'] ?? false,
                            'is_running' => $entryData['is_running'] ?? false,
                            'billable' => $entryData['billable'] ?? true,
                            'budgeted' => $entryData['budgeted'] ?? false,
                            'billable_rate' => $entryData['billable_rate'] ?? null,
                            'cost_rate' => $entryData['cost_rate'] ?? null,
                            'started_time' => $entryData['started_time'] ?? null,
                            'ended_time' => $entryData['ended_time'] ?? null,
                            'timer_started_at' => isset($entryData['timer_started_at'])
                                ? Carbon::parse($entryData['timer_started_at'])
                                : null,
                            'external_reference' => $entryData['external_reference'] ?? null,
                        ]
                    );

                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            }
            $this->newLine();
        }

        // Sync invoices
        if (! $timeOnly) {
            $this->info('💰 Syncing ALL invoices since '.$fromDate->format('Y-m-d').'...');

            $invoices = $harvestService->listInvoices($credential, $fromDate);
            $this->info('  Found '.count($invoices).' invoices in Harvest');

            if (! $dryRun) {
                $bar = $this->output->createProgressBar(count($invoices));
                $bar->start();

                foreach ($invoices as $invoiceData) {
                    HarvestInvoice::updateOrCreate(
                        ['harvest_id' => $invoiceData['id']],
                        [
                            'client_name' => $invoiceData['client']['name'] ?? null,
                            'client_harvest_id' => $invoiceData['client']['id'] ?? null,
                            'number' => $invoiceData['number'] ?? null,
                            'purchase_order' => $invoiceData['purchase_order'] ?? null,
                            'amount' => $invoiceData['amount'] ?? 0,
                            'due_amount' => $invoiceData['due_amount'] ?? 0,
                            'tax' => $invoiceData['tax'] ?? 0,
                            'tax_amount' => $invoiceData['tax_amount'] ?? 0,
                            'tax2' => $invoiceData['tax2'] ?? 0,
                            'tax2_amount' => $invoiceData['tax2_amount'] ?? 0,
                            'discount' => $invoiceData['discount'] ?? 0,
                            'discount_amount' => $invoiceData['discount_amount'] ?? 0,
                            'subject' => $invoiceData['subject'] ?? null,
                            'notes' => $invoiceData['notes'] ?? null,
                            'line_items' => $invoiceData['line_items'] ?? null,
                            'currency' => $invoiceData['currency'] ?? 'USD',
                            'state' => $invoiceData['state'] ?? 'draft',
                            'period_start' => isset($invoiceData['period_start'])
                                ? Carbon::parse($invoiceData['period_start'])
                                : null,
                            'period_end' => isset($invoiceData['period_end'])
                                ? Carbon::parse($invoiceData['period_end'])
                                : null,
                            'issue_date' => isset($invoiceData['issue_date'])
                                ? Carbon::parse($invoiceData['issue_date'])
                                : null,
                            'due_date' => isset($invoiceData['due_date'])
                                ? Carbon::parse($invoiceData['due_date'])
                                : null,
                            'payment_term' => $invoiceData['payment_term'] ?? null,
                            'sent_at' => isset($invoiceData['sent_at'])
                                ? Carbon::parse($invoiceData['sent_at'])
                                : null,
                            'paid_at' => isset($invoiceData['paid_at'])
                                ? Carbon::parse($invoiceData['paid_at'])
                                : null,
                            'paid_date' => isset($invoiceData['paid_date'])
                                ? Carbon::parse($invoiceData['paid_date'])
                                : null,
                            'closed_at' => isset($invoiceData['closed_at'])
                                ? Carbon::parse($invoiceData['closed_at'])
                                : null,
                        ]
                    );

                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            }
            $this->newLine();
        }

        // Run reconciliation
        if (! $dryRun && ! $timeOnly && ! $invoicesOnly) {
            $this->info('🔗 Running reconciliation...');
            $results = $syncService->reconcile();
            $this->table(
                ['Operation', 'Count'],
                collect($results)->map(fn ($count, $op) => [$op, $count])->values()->toArray()
            );
            $this->newLine();
        }

        // Show final stats
        $this->info('=== Final Stats ===');
        $this->showCurrentStats();

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to actually import data.');
        }

        $this->newLine();
        $this->info('✅ Harvest full import complete!');

        return Command::SUCCESS;
    }

    protected function showCurrentStats(): void
    {
        $stats = [
            ['Clients (local)', Client::count()],
            ['Clients (with Harvest ID)', Client::whereNotNull('harvest_client_id')->count()],
            ['Projects (Harvest)', HarvestProject::count()],
            ['Task Categories', HarvestTaskCategory::count()],
            ['Time Entries', TimeEntry::count()],
            ['Invoices', HarvestInvoice::count()],
            ['Invoices (paid)', HarvestInvoice::where('state', 'paid')->count()],
            ['Total Revenue (paid)', '$'.number_format(HarvestInvoice::where('state', 'paid')->sum('amount'), 2)],
        ];

        $this->table(['Metric', 'Count'], $stats);
    }
}
