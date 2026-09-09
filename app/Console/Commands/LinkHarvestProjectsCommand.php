<?php

namespace App\Console\Commands;

use App\Models\HarvestProject;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Link HarvestProjects to native Projects by matching names.
 *
 * This enables time entries (which have harvest_project_id) to be
 * accessed via the native Project model.
 */
class LinkHarvestProjectsCommand extends Command
{
    protected $signature = 'harvest:link-projects {--dry-run : Show matches without updating}';

    protected $description = 'Link HarvestProjects to native Projects by name matching';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $harvestProjects = HarvestProject::whereNull('project_id')->get();
        $this->info("Found {$harvestProjects->count()} unlinked HarvestProjects");

        $linked = 0;

        foreach ($harvestProjects as $hp) {
            // Try exact match first
            $project = Project::where('name', $hp->name)->first();

            // Try normalized match
            if (! $project) {
                $normalized = Str::slug($hp->name, ' ');
                $project = Project::whereRaw('LOWER(name) = ?', [strtolower($normalized)])->first();
            }

            // Try partial match (HarvestProject name contains Project name or vice versa)
            if (! $project) {
                $project = Project::where(function ($q) use ($hp) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($hp->name).'%'])
                        ->orWhereRaw('? LIKE CONCAT("%", LOWER(name), "%")', [strtolower($hp->name)]);
                })->first();
            }

            if ($project) {
                $this->line("  {$hp->name} → {$project->name} (Project #{$project->id})");

                if (! $dryRun) {
                    $hp->update(['project_id' => $project->id]);
                    $project->update(['harvest_project_id' => $hp->harvest_id]);
                }

                $linked++;
            } else {
                $this->warn("  {$hp->name} - No matching project found");
            }
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would link {$linked} projects");
        } else {
            $this->info("Linked {$linked} HarvestProjects to native Projects");
        }

        return 0;
    }
}
