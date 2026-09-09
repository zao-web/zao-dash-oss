<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\HarvestCredential;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\HarvestTaskCategory;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Harvest\HarvestService;
use App\Services\Harvest\HarvestSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncHarvestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $credentialId = null,
        public bool $syncClients = true,
        public bool $syncProjects = true,
        public bool $syncTimeEntries = true,
        public bool $syncInvoices = true,
        public bool $syncTaskCategories = true,
        public bool $syncContacts = true,
        public ?string $fromDate = null
    ) {}

    public function handle(HarvestService $harvestService, HarvestSyncService $syncService): void
    {
        $credentials = $this->credentialId
            ? HarvestCredential::where('id', $this->credentialId)->get()
            : HarvestCredential::where('is_active', true)->get();

        foreach ($credentials as $credential) {
            try {
                $this->syncCredential($credential, $harvestService, $syncService);
            } catch (\Exception $e) {
                Log::error('Harvest sync failed', [
                    'credential_id' => $credential->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // After syncing all credentials, reconcile with Zao Dash entities
        try {
            $reconcileResults = $syncService->reconcile();
            Log::info('Harvest reconciliation completed', $reconcileResults);
        } catch (\Exception $e) {
            Log::error('Harvest reconciliation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncCredential(HarvestCredential $credential, HarvestService $harvestService, HarvestSyncService $syncService): void
    {
        $this->initSyncTracking($credential);

        try {
            Log::info('Syncing Harvest data', ['user_id' => $credential->user_id]);

            if ($this->syncClients) {
                $this->syncClientsData($credential, $harvestService, $syncService);
                $this->updateSyncProgress(10, 'clients');
            }

            if ($this->syncTaskCategories) {
                $this->syncTaskCategoriesData($credential, $harvestService);
                $this->updateSyncProgress(20, 'task categories');
            }

            if ($this->syncProjects) {
                $this->syncProjectsData($credential, $harvestService);
                $this->updateSyncProgress(40, 'projects');
            }

            if ($this->syncTimeEntries) {
                $this->syncTimeEntriesData($credential, $harvestService);
                $this->updateSyncProgress(60, 'time entries');
            }

            if ($this->syncInvoices) {
                $this->syncInvoicesData($credential, $harvestService);
                $this->updateSyncProgress(80, 'invoices');
            }

            if ($this->syncContacts) {
                $this->syncContactsData($credential, $harvestService);
                $this->updateSyncProgress(95, 'contacts');
            }

            $credential->update(['last_synced_at' => now()]);
            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function syncClientsData(HarvestCredential $credential, HarvestService $harvestService, HarvestSyncService $syncService): void
    {
        Log::info('Syncing Harvest clients');

        $clients = $harvestService->listClients($credential);
        $stats = $syncService->syncAllClientsFromHarvest($clients);

        Log::info('Harvest clients sync complete', $stats);
    }

    protected function syncTaskCategoriesData(HarvestCredential $credential, HarvestService $harvestService): void
    {
        Log::info('Syncing Harvest task categories');

        $tasks = $harvestService->listTasks($credential);

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

    protected function syncProjectsData(HarvestCredential $credential, HarvestService $harvestService): void
    {
        Log::info('Syncing Harvest projects');

        $projects = $harvestService->listProjects($credential);

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

    protected function syncTimeEntriesData(HarvestCredential $credential, HarvestService $harvestService): void
    {
        Log::info('Syncing Harvest time entries');

        $fromDate = $this->fromDate
            ? Carbon::parse($this->fromDate)
            : ($credential->last_synced_at ?? now()->subDays(30));

        $entries = $harvestService->listTimeEntries($credential, $fromDate);

        foreach ($entries as $entryData) {
            // Find the related project
            $harvestProject = HarvestProject::where('harvest_id', $entryData['project']['id'])->first();

            // Find the related task category
            $taskCategory = HarvestTaskCategory::where('harvest_id', $entryData['task']['id'])->first();

            // Try to match user by email
            $user = User::where('email', $entryData['user']['email'] ?? '')->first();

            TimeEntry::updateOrCreate(
                ['harvest_id' => $entryData['id']],
                [
                    'user_id' => $user?->id,
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
        }
    }

    protected function syncInvoicesData(HarvestCredential $credential, HarvestService $harvestService): void
    {
        Log::info('Syncing Harvest invoices');

        $fromDate = $this->fromDate
            ? Carbon::parse($this->fromDate)
            : now()->subMonths(6);

        $invoices = $harvestService->listInvoices($credential, $fromDate);

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
        }
    }

    protected function syncContactsData(HarvestCredential $credential, HarvestService $harvestService): void
    {
        Log::info('Syncing Harvest contacts');

        $contacts = $harvestService->listContacts($credential);
        $synced = 0;
        $skipped = 0;

        foreach ($contacts as $contactData) {
            $harvestClientId = $contactData['client']['id'] ?? null;
            if (! $harvestClientId) {
                $skipped++;

                continue;
            }

            // Find the local client by harvest_client_id
            $client = Client::where('harvest_client_id', $harvestClientId)->first();
            if (! $client) {
                $skipped++;

                continue;
            }

            // Build contact name from first/last
            $firstName = $contactData['first_name'] ?? '';
            $lastName = $contactData['last_name'] ?? '';
            $name = trim("$firstName $lastName") ?: 'Unknown Contact';

            ClientContact::updateOrCreate(
                ['harvest_contact_id' => $contactData['id']],
                [
                    'client_id' => $client->id,
                    'name' => $name,
                    'email' => $contactData['email'] ?? '',
                    'phone' => $contactData['phone_mobile'] ?? $contactData['phone_office'] ?? null,
                    'title' => $contactData['title'] ?? null,
                    'is_primary' => false, // Harvest doesn't have primary flag
                ]
            );
            $synced++;
        }

        Log::info('Harvest contacts sync complete', [
            'synced' => $synced,
            'skipped' => $skipped,
        ]);
    }
}
