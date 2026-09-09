<?php

namespace App\Observers;

use App\Models\HarvestCredential;
use App\Models\Project;
use App\Services\Harvest\HarvestSyncService;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    public function __construct(
        protected HarvestSyncService $syncService
    ) {}

    /**
     * Handle the Project "deleted" event (soft delete).
     * Archive the project in Harvest if linked.
     */
    public function deleted(Project $project): void
    {
        if (! $project->harvest_project_id) {
            return;
        }

        $credential = $this->getHarvestCredential();
        if (! $credential) {
            Log::warning('Cannot sync project archive to Harvest - no active credential', [
                'project_id' => $project->id,
            ]);

            return;
        }

        try {
            $this->syncService->archiveProjectInHarvest($project, $credential);
        } catch (\Exception $e) {
            Log::error('Failed to archive project in Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Project "restored" event.
     * Reactivate the project in Harvest if linked.
     */
    public function restored(Project $project): void
    {
        if (! $project->harvest_project_id) {
            return;
        }

        $credential = $this->getHarvestCredential();
        if (! $credential) {
            Log::warning('Cannot sync project restore to Harvest - no active credential', [
                'project_id' => $project->id,
            ]);

            return;
        }

        try {
            $this->syncService->restoreProjectInHarvest($project, $credential);
        } catch (\Exception $e) {
            Log::error('Failed to restore project in Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get an active Harvest credential for API calls.
     */
    protected function getHarvestCredential(): ?HarvestCredential
    {
        return HarvestCredential::where('is_active', true)->first();
    }
}
