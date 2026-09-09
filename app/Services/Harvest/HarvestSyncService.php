<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\HarvestCredential;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\Project;
use App\Models\RetainerPeriod;
use App\Models\StrategicGoal;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HarvestSyncService
{
    public function __construct(
        protected HarvestService $harvestService
    ) {}

    /**
     * Sync ALL clients directly from Harvest API.
     * This ensures clients without projects are also imported.
     */
    public function syncAllClientsFromHarvest(array $harvestClients): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'restored' => 0];

        // Track which Harvest client IDs we've seen (for archival logic)
        $seenHarvestIds = [];

        foreach ($harvestClients as $harvestClient) {
            $harvestId = $harvestClient['id'];
            $clientName = $harvestClient['name'];
            $isActive = $harvestClient['is_active'] ?? true;

            $seenHarvestIds[] = $harvestId;

            // Check if Client already exists with this harvest_client_id
            $client = Client::withTrashed()
                ->where('harvest_client_id', $harvestId)
                ->first();

            if (! $client) {
                // Create new client
                $client = Client::create([
                    'name' => $clientName,
                    'slug' => $this->generateUniqueClientSlug($clientName),
                    'harvest_client_id' => $harvestId,
                    'status' => $isActive ? 'active' : 'archived',
                ]);
                $stats['created']++;

                Log::info('Created client from Harvest API', [
                    'client_id' => $client->id,
                    'harvest_client_id' => $harvestId,
                    'name' => $clientName,
                ]);
            } else {
                $changes = [];

                // Update name if changed
                if ($client->name !== $clientName) {
                    $changes['name'] = $clientName;
                }

                // Handle active/archived status from Harvest
                if ($isActive && $client->status === 'archived') {
                    $changes['status'] = 'active';
                    $stats['restored']++;
                } elseif (! $isActive && $client->status === 'active') {
                    $changes['status'] = 'archived';
                }

                // Restore if trashed and now active
                if ($client->trashed() && $isActive) {
                    $client->restore();
                    $changes['status'] = 'active';
                    $stats['restored']++;
                }

                if (! empty($changes)) {
                    $client->update($changes);
                    $stats['updated']++;
                }
            }
        }

        // Store seen IDs for archival check
        $this->lastSyncedHarvestClientIds = $seenHarvestIds;

        return $stats;
    }

    /**
     * Harvest client IDs from last sync (for archival logic).
     */
    protected array $lastSyncedHarvestClientIds = [];

    /**
     * Sync Harvest clients to Zao Dash clients (from projects - legacy method).
     * Creates Client records for any Harvest clients not yet imported.
     */
    public function syncClientsFromHarvest(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'archived' => 0];

        // Get unique Harvest clients from projects
        $harvestClients = HarvestProject::select('client_harvest_id', 'client_name')
            ->whereNotNull('client_harvest_id')
            ->distinct()
            ->get();

        foreach ($harvestClients as $harvestClient) {
            // Check if Client already exists with this harvest_client_id
            $client = Client::withTrashed()
                ->where('harvest_client_id', $harvestClient->client_harvest_id)
                ->first();

            if (! $client) {
                // Create new client with generated slug
                $client = Client::create([
                    'name' => $harvestClient->client_name,
                    'slug' => $this->generateUniqueClientSlug($harvestClient->client_name),
                    'harvest_client_id' => $harvestClient->client_harvest_id,
                    'status' => 'active',
                ]);
                $stats['created']++;

                Log::info('Created client from Harvest', [
                    'client_id' => $client->id,
                    'harvest_client_id' => $harvestClient->client_harvest_id,
                    'name' => $client->name,
                ]);
            } else {
                // Update name if changed
                if ($client->name !== $harvestClient->client_name) {
                    $client->update(['name' => $harvestClient->client_name]);
                    $stats['updated']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Sync Harvest projects to Zao Dash projects.
     * Creates Project records and links them to HarvestProjects.
     */
    public function syncProjectsFromHarvest(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'archived' => 0, 'restored' => 0];

        $harvestProjects = HarvestProject::all();

        foreach ($harvestProjects as $harvestProject) {
            // Find or create the parent client
            $client = $this->findOrCreateClientForHarvestProject($harvestProject);

            if (! $client) {
                Log::warning('Could not find/create client for Harvest project', [
                    'harvest_project_id' => $harvestProject->harvest_id,
                ]);

                continue;
            }

            // Check if Project already exists linked to this HarvestProject
            $project = Project::withTrashed()
                ->where('harvest_project_id', $harvestProject->harvest_id)
                ->first();

            if (! $project) {
                // Create new project with calculated budget
                $projectData = $this->buildProjectDataFromHarvest($harvestProject, $client);
                $projectData['slug'] = $this->generateUniqueSlug($harvestProject->name);

                $project = Project::create($projectData);
                $stats['created']++;

                Log::info('Created project from Harvest', [
                    'project_id' => $project->id,
                    'harvest_project_id' => $harvestProject->harvest_id,
                    'name' => $project->name,
                ]);
            } else {
                // Update project with latest Harvest data
                $projectData = $this->buildProjectDataFromHarvest($harvestProject, $client);
                $changes = [];

                // Check each field for changes
                foreach (['name', 'budget', 'hourly_rate', 'budget_hours', 'budget_is_monthly', 'type', 'description'] as $field) {
                    if (isset($projectData[$field]) && $projectData[$field] != $project->$field) {
                        $changes[$field] = $projectData[$field];
                    }
                }

                // Handle archive status sync based on Harvest is_active flag
                if ($harvestProject->is_active && $project->status === 'archived') {
                    $changes['status'] = 'active';
                    $stats['restored']++;
                    Log::info('Restored project from Harvest reactivation', [
                        'project_id' => $project->id,
                    ]);
                } elseif (! $harvestProject->is_active && $project->status !== 'archived') {
                    $changes['status'] = 'archived';
                    $stats['archived']++;
                    Log::info('Archived project - inactive in Harvest', [
                        'project_id' => $project->id,
                    ]);
                }

                if (! empty($changes)) {
                    $project->update($changes);
                    $stats['updated']++;
                }
            }

            // Link HarvestProject to Client and Project
            $harvestProject->update([
                'client_id' => $client->id,
                'project_id' => $project->id,
            ]);
        }

        return $stats;
    }

    /**
     * Sync retainer periods from Harvest project budgets.
     * Creates/updates RetainerPeriod for projects with monthly budgets.
     */
    public function syncRetainersFromHarvest(): array
    {
        $stats = ['created' => 0, 'updated' => 0];
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        // Harvest projects with monthly budgets (legacy — being phased out)
        $retainerProjects = HarvestProject::where('budget_is_monthly', true)
            ->whereNotNull('budget')
            ->where('budget', '>', 0)
            ->whereNotNull('client_id')
            ->get();

        foreach ($retainerProjects as $harvestProject) {
            $hoursUsed = TimeEntry::where('harvest_project_id', $harvestProject->id)
                ->whereBetween('spent_date', [$periodStart, $periodEnd])
                ->sum('hours');

            $retainer = RetainerPeriod::firstOrNew([
                'client_id' => $harvestProject->client_id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $isNew = ! $retainer->exists;

            $retainer->fill([
                'hours_included' => $harvestProject->budget,
                'hours_used' => $hoursUsed,
                'status' => 'active',
                'harvest_project_id' => $harvestProject->harvest_id,
            ]);

            $retainer->save();

            $isNew ? $stats['created']++ : $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Update hours used on all active retainer periods.
     * Call this after syncing time entries.
     */
    public function updateRetainerHours(): int
    {
        $updated = 0;

        $activeRetainers = RetainerPeriod::where('status', 'active')
            ->whereNotNull('harvest_project_id')
            ->get();

        foreach ($activeRetainers as $retainer) {
            $hoursUsed = TimeEntry::whereHas('harvestProject', function ($q) use ($retainer) {
                $q->where('harvest_id', $retainer->harvest_project_id);
            })
                ->whereBetween('spent_date', [$retainer->period_start, $retainer->period_end])
                ->sum('hours');

            if ($retainer->hours_used != $hoursUsed) {
                $retainer->update(['hours_used' => $hoursUsed]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Backfill client_id on TimeEntry records based on HarvestProject links.
     */
    public function backfillTimeEntryClients(): int
    {
        $updated = 0;

        // Find time entries without client_id but with harvest_project_id
        $entries = TimeEntry::whereNull('client_id')
            ->whereNotNull('harvest_project_id')
            ->get();

        foreach ($entries as $entry) {
            $harvestProject = HarvestProject::find($entry->harvest_project_id);

            if ($harvestProject && $harvestProject->client_id) {
                $entry->update([
                    'client_id' => $harvestProject->client_id,
                    'project_id' => $harvestProject->project_id,
                ]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Backfill client_id on HarvestInvoice records.
     */
    public function backfillInvoiceClients(): int
    {
        $updated = 0;

        $invoices = HarvestInvoice::whereNull('client_id')
            ->whereNotNull('client_harvest_id')
            ->get();

        foreach ($invoices as $invoice) {
            $client = Client::where('harvest_client_id', $invoice->client_harvest_id)->first();

            if ($client) {
                $invoice->update(['client_id' => $client->id]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Archive clients that are inactive in Harvest.
     * Note: We no longer archive clients just because they have no projects.
     * Clients without projects are legitimate (pre-sales, churned but kept for records, etc.)
     */
    protected function archiveInactiveClients(): int
    {
        // This method is now a no-op since client status is synced directly from Harvest
        // in syncAllClientsFromHarvest(). We respect Harvest's is_active flag.
        return 0;
    }

    /**
     * Find or create a Client for a HarvestProject.
     */
    protected function findOrCreateClientForHarvestProject(HarvestProject $harvestProject): ?Client
    {
        if (! $harvestProject->client_harvest_id) {
            return null;
        }

        $client = Client::withTrashed()
            ->where('harvest_client_id', $harvestProject->client_harvest_id)
            ->first();

        if (! $client) {
            $clientName = $harvestProject->client_name ?? 'Unknown Client';
            $client = Client::create([
                'name' => $clientName,
                'slug' => $this->generateUniqueClientSlug($clientName),
                'harvest_client_id' => $harvestProject->client_harvest_id,
                'status' => 'active',
            ]);
        } elseif ($client->trashed()) {
            // Restore if we're creating a project for it
            $client->restore();
        }

        return $client;
    }

    /**
     * Generate a unique slug for a project.
     */
    protected function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (Project::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Generate a unique slug for a client.
     */
    protected function generateUniqueClientSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (Client::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Build project data array from Harvest project.
     * Calculates budget = hours × hourly_rate for proper dollar amounts.
     */
    protected function buildProjectDataFromHarvest(HarvestProject $harvestProject, Client $client): array
    {
        $budgetHours = $harvestProject->budget;
        $hourlyRate = $harvestProject->hourly_rate;

        // Calculate dollar budget from hours × rate
        $budgetDollars = null;
        if ($budgetHours && $hourlyRate) {
            $budgetDollars = $budgetHours * $hourlyRate;
        }

        // Determine project type based on monthly budget flag
        $type = $harvestProject->budget_is_monthly ? 'retainer' : 'project';

        return [
            'name' => $harvestProject->name,
            'client_id' => $client->id,
            'harvest_project_id' => $harvestProject->harvest_id,
            'budget' => $budgetDollars,
            'hourly_rate' => $hourlyRate,
            'budget_hours' => $budgetHours,
            'budget_is_monthly' => $harvestProject->budget_is_monthly ?? false,
            'type' => $type,
            'description' => $harvestProject->notes,
            'status' => $harvestProject->is_active ? 'active' : 'archived',
        ];
    }

    /**
     * Archive a project in Harvest (bidirectional sync).
     */
    public function archiveProjectInHarvest(Project $project, HarvestCredential $credential): bool
    {
        if (! $project->harvest_project_id) {
            return false;
        }

        try {
            $this->harvestService->updateProject($credential, $project->harvest_project_id, [
                'is_active' => false,
            ]);

            // Update local HarvestProject record
            HarvestProject::where('harvest_id', $project->harvest_project_id)
                ->update(['is_active' => false]);

            Log::info('Archived project in Harvest', [
                'project_id' => $project->id,
                'harvest_project_id' => $project->harvest_project_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to archive project in Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Restore/reactivate a project in Harvest.
     */
    public function restoreProjectInHarvest(Project $project, HarvestCredential $credential): bool
    {
        if (! $project->harvest_project_id) {
            return false;
        }

        try {
            $this->harvestService->updateProject($credential, $project->harvest_project_id, [
                'is_active' => true,
            ]);

            HarvestProject::where('harvest_id', $project->harvest_project_id)
                ->update(['is_active' => true]);

            Log::info('Restored project in Harvest', [
                'project_id' => $project->id,
                'harvest_project_id' => $project->harvest_project_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to restore project in Harvest', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function syncGoalPeriodRevenue(string $dateField = 'paid_at'): int
    {
        $updated = 0;

        $goals = StrategicGoal::with('periods')
            ->where('status', 'active')
            ->get();

        foreach ($goals as $goal) {
            foreach ($goal->periods as $period) {
                $revenue = (float) HarvestInvoice::where('state', 'paid')
                    ->whereNotNull($dateField)
                    ->whereBetween($dateField, [$period->period_start, $period->period_end])
                    ->sum('amount');

                if (abs($period->revenue_actual - $revenue) > 0.01) {
                    $period->revenue_actual = $revenue;
                    $period->calculateVariance();
                    $period->updateStatus();
                    $period->save();
                    $updated++;
                }
            }
        }

        if ($updated > 0) {
            Log::info('Synced goal period revenue from invoices', ['updated' => $updated]);
        }

        return $updated;
    }

    public function reconcile(): array
    {
        $results = [
            'clients' => $this->syncClientsFromHarvest(),
            'projects' => $this->syncProjectsFromHarvest(),
            'retainers' => $this->syncRetainersFromHarvest(),
            'time_entries_backfilled' => $this->backfillTimeEntryClients(),
            'invoices_backfilled' => $this->backfillInvoiceClients(),
            'retainer_hours_updated' => $this->updateRetainerHours(),
            'goal_periods_updated' => $this->syncGoalPeriodRevenue(),
        ];

        Log::info('Harvest reconciliation complete', $results);

        return $results;
    }
}
