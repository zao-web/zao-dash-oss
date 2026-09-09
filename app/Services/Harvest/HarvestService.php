<?php

namespace App\Services\Harvest;

use App\Models\HarvestCredential;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HarvestService
{
    protected HarvestOAuthService $oauth;

    public function __construct(HarvestOAuthService $oauth)
    {
        $this->oauth = $oauth;
    }

    protected function client(HarvestCredential $credential): PendingRequest
    {
        $token = $this->oauth->getValidTokenForCredential($credential);

        return Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Harvest-Account-Id' => $credential->account_id,
            'User-Agent' => 'Zao Dashboard (support@example.com)',
        ])->baseUrl('https://api.harvestapp.com/v2');
    }

    public function listTasks(HarvestCredential $credential): array
    {
        $tasks = [];
        $page = 1;

        do {
            $response = $this->client($credential)->get('/tasks', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch tasks: '.$response->body());
            }

            $data = $response->json();
            $tasks = array_merge($tasks, $data['tasks'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $tasks;
    }

    public function listProjects(HarvestCredential $credential): array
    {
        $projects = [];
        $page = 1;

        do {
            $response = $this->client($credential)->get('/projects', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch projects: '.$response->body());
            }

            $data = $response->json();
            $projects = array_merge($projects, $data['projects'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $projects;
    }

    public function listTimeEntries(HarvestCredential $credential, Carbon $from, ?Carbon $to = null): array
    {
        $entries = [];
        $page = 1;

        $params = [
            'per_page' => 100,
            'from' => $from->toDateString(),
        ];

        if ($to) {
            $params['to'] = $to->toDateString();
        }

        do {
            $params['page'] = $page;
            $response = $this->client($credential)->get('/time_entries', $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch time entries: '.$response->body());
            }

            $data = $response->json();
            $entries = array_merge($entries, $data['time_entries'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $entries;
    }

    public function listInvoices(HarvestCredential $credential, Carbon $from, ?Carbon $to = null): array
    {
        $invoices = [];
        $page = 1;

        $params = [
            'per_page' => 100,
            'from' => $from->toDateString(),
        ];

        if ($to) {
            $params['to'] = $to->toDateString();
        }

        do {
            $params['page'] = $page;
            $response = $this->client($credential)->get('/invoices', $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch invoices: '.$response->body());
            }

            $data = $response->json();
            $invoices = array_merge($invoices, $data['invoices'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $invoices;
    }

    /**
     * Create a time entry in Harvest.
     * Used for manual entries and automated Dev Agent time logging.
     */
    public function createTimeEntry(HarvestCredential $credential, array $data): array
    {
        $response = $this->client($credential)->post('/time_entries', $data);

        if (! $response->successful()) {
            throw new \Exception('Failed to create time entry: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create time entry for a completed task with estimated hours.
     * Used by Dev Agent after task completion.
     *
     * @param  int  $harvestProjectId  Harvest project ID
     * @param  int  $harvestTaskId  Harvest task category ID (e.g., "Development")
     * @param  float  $hours  Estimated hours (what a senior engineer would take)
     * @param  string  $notes  Description of work (usually task name + summary)
     * @param  Carbon|null  $spentDate  Date to log (defaults to today)
     * @param  array  $metadata  Additional context (task_id, agent_run_id, etc.)
     */
    public function logAgentTaskTime(
        HarvestCredential $credential,
        int $harvestProjectId,
        int $harvestTaskId,
        float $hours,
        string $notes,
        ?Carbon $spentDate = null,
        array $metadata = []
    ): array {
        $entry = [
            'project_id' => $harvestProjectId,
            'task_id' => $harvestTaskId,
            'spent_date' => ($spentDate ?? now())->toDateString(),
            'hours' => $hours,
            'notes' => $this->formatAgentNotes($notes, $metadata),
        ];

        return $this->createTimeEntry($credential, $entry);
    }

    /**
     * Format notes for agent-logged time entries.
     * Includes metadata for traceability.
     */
    protected function formatAgentNotes(string $notes, array $metadata): string
    {
        $parts = [$notes];

        if (! empty($metadata['task_id'])) {
            $parts[] = "[Task #{$metadata['task_id']}]";
        }

        if (! empty($metadata['agent_run_id'])) {
            $parts[] = "[Agent Run #{$metadata['agent_run_id']}]";
        }

        // Mark as AI-estimated for transparency
        $parts[] = '[AI-estimated time]';

        return implode(' ', $parts);
    }

    /**
     * Start a timer for a task.
     */
    public function startTimer(
        HarvestCredential $credential,
        int $harvestProjectId,
        int $harvestTaskId,
        string $notes
    ): array {
        return $this->createTimeEntry($credential, [
            'project_id' => $harvestProjectId,
            'task_id' => $harvestTaskId,
            'spent_date' => now()->toDateString(),
            'notes' => $notes,
            'is_running' => true,
        ]);
    }

    /**
     * Stop a running timer.
     */
    public function stopTimer(HarvestCredential $credential, int $timeEntryId): array
    {
        $response = $this->client($credential)->patch("/time_entries/{$timeEntryId}/stop");

        if (! $response->successful()) {
            throw new \Exception('Failed to stop timer: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get project task assignments (which task categories are assigned to a project).
     */
    public function getProjectTaskAssignments(HarvestCredential $credential, int $harvestProjectId): array
    {
        $assignments = [];
        $page = 1;

        do {
            $response = $this->client($credential)->get('/task_assignments', [
                'project_id' => $harvestProjectId,
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch task assignments: '.$response->body());
            }

            $data = $response->json();
            $assignments = array_merge($assignments, $data['task_assignments'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $assignments;
    }

    /**
     * Update a project in Harvest.
     */
    public function updateProject(HarvestCredential $credential, int $harvestProjectId, array $data): array
    {
        $response = $this->client($credential)->patch("/projects/{$harvestProjectId}", $data);

        if (! $response->successful()) {
            throw new \Exception('Failed to update project: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a single project from Harvest.
     */
    public function getProject(HarvestCredential $credential, int $harvestProjectId): array
    {
        $response = $this->client($credential)->get("/projects/{$harvestProjectId}");

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch project: '.$response->body());
        }

        return $response->json();
    }

    /**
     * List clients from Harvest.
     */
    public function listClients(HarvestCredential $credential): array
    {
        $clients = [];
        $page = 1;

        do {
            $response = $this->client($credential)->get('/clients', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch clients: '.$response->body());
            }

            $data = $response->json();
            $clients = array_merge($clients, $data['clients'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $clients;
    }

    /**
     * Update a client in Harvest.
     */
    public function updateClient(HarvestCredential $credential, int $harvestClientId, array $data): array
    {
        $response = $this->client($credential)->patch("/clients/{$harvestClientId}", $data);

        if (! $response->successful()) {
            throw new \Exception('Failed to update client: '.$response->body());
        }

        return $response->json();
    }

    /**
     * List contacts from Harvest.
     */
    public function listContacts(HarvestCredential $credential): array
    {
        $contacts = [];
        $page = 1;

        do {
            $response = $this->client($credential)->get('/contacts', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch contacts: '.$response->body());
            }

            $data = $response->json();
            $contacts = array_merge($contacts, $data['contacts'] ?? []);
            $page++;
        } while (($data['total_pages'] ?? 0) >= $page);

        return $contacts;
    }
}
