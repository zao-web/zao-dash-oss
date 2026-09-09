<?php

namespace App\Console\Commands;

use App\Models\HarvestProject;
use App\Models\TimeEntry;
use Illuminate\Console\Command;

/**
 * Backfill project_id on time entries based on their HarvestProject linkage.
 *
 * Harvest-synced time entries only have harvest_project_id set. When a
 * HarvestProject is linked to a native Project, the time entries should
 * also have project_id set for direct relationship access.
 */
class LinkTimeEntriesToProjectsCommand extends Command
{
    protected $signature = 'time-entries:link-to-projects {--dry-run : Show what would be updated}';

    protected $description = 'Link time entries to native Projects based on HarvestProject mappings';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Find all HarvestProjects that are linked to native Projects
        $linkedHarvestProjects = HarvestProject::whereNotNull('project_id')->get();

        $this->info("Found {$linkedHarvestProjects->count()} HarvestProjects linked to native Projects");

        $totalUpdated = 0;

        foreach ($linkedHarvestProjects as $hp) {
            // Find time entries with this harvest_project_id but no project_id
            $entries = TimeEntry::where('harvest_project_id', $hp->id)
                ->whereNull('project_id')
                ->get();

            if ($entries->isEmpty()) {
                continue;
            }

            $this->line("  {$hp->name}: {$entries->count()} entries to link to Project #{$hp->project_id}");

            if (! $dryRun) {
                TimeEntry::where('harvest_project_id', $hp->id)
                    ->whereNull('project_id')
                    ->update(['project_id' => $hp->project_id]);
            }

            $totalUpdated += $entries->count();
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would update {$totalUpdated} time entries");
        } else {
            $this->info("Updated {$totalUpdated} time entries with project_id");
        }

        return 0;
    }
}
