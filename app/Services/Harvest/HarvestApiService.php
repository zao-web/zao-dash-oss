<?php

namespace App\Services\Harvest;

use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\HarvestTaskCategory;
use App\Models\ProfitabilitySnapshot;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HarvestApiService
{
    protected HarvestOAuthService $oauth;

    public function __construct(HarvestOAuthService $oauth)
    {
        $this->oauth = $oauth;
    }

    protected function client(User $user): PendingRequest
    {
        $credential = $user->harvestCredential;

        if (! $credential) {
            throw new \Exception('User has no Harvest credentials');
        }

        $token = $this->oauth->getValidToken($user);

        return Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Harvest-Account-Id' => $credential->account_id,
            'User-Agent' => 'Zao Dashboard (support@example.com)',
        ])->baseUrl('https://api.harvestapp.com/v2');
    }

    // Projects
    public function syncProjects(User $user): int
    {
        $count = 0;
        $page = 1;

        do {
            $response = $this->client($user)->get('/projects', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch projects: '.$response->body());
            }

            $data = $response->json();

            foreach ($data['projects'] as $project) {
                $this->upsertProject($project);
                $count++;
            }

            $page++;
        } while ($data['total_pages'] >= $page);

        return $count;
    }

    protected function upsertProject(array $data): HarvestProject
    {
        return HarvestProject::updateOrCreate(
            ['harvest_id' => $data['id']],
            [
                'harvest_client_id' => $data['client']['id'] ?? null,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_active' => $data['is_active'],
                'is_billable' => $data['is_billable'],
                'bill_by' => $data['bill_by'],
                'hourly_rate' => $data['hourly_rate'],
                'budget' => $data['budget'],
                'budget_by' => $data['budget_by'],
                'budget_is_monthly' => $data['budget_is_monthly'],
            ]
        );
    }

    // Task Categories
    public function syncTaskCategories(User $user): int
    {
        $count = 0;
        $page = 1;

        do {
            $response = $this->client($user)->get('/tasks', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch tasks: '.$response->body());
            }

            $data = $response->json();

            foreach ($data['tasks'] as $task) {
                HarvestTaskCategory::updateOrCreate(
                    ['harvest_id' => $task['id']],
                    [
                        'name' => $task['name'],
                        'is_default' => $task['is_default'],
                        'default_hourly_rate' => $task['default_hourly_rate'],
                        'is_active' => $task['is_active'],
                    ]
                );
                $count++;
            }

            $page++;
        } while ($data['total_pages'] >= $page);

        return $count;
    }

    // Time Entries
    public function syncTimeEntries(User $user, ?string $from = null, ?string $to = null): int
    {
        $count = 0;
        $page = 1;

        $params = [
            'per_page' => 100,
        ];

        if ($from) {
            $params['from'] = $from;
        }
        if ($to) {
            $params['to'] = $to;
        }

        do {
            $params['page'] = $page;
            $response = $this->client($user)->get('/time_entries', $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch time entries: '.$response->body());
            }

            $data = $response->json();

            foreach ($data['time_entries'] as $entry) {
                $this->upsertTimeEntry($entry, $user);
                $count++;
            }

            $page++;
        } while ($data['total_pages'] >= $page);

        return $count;
    }

    protected function upsertTimeEntry(array $data, User $user): TimeEntry
    {
        // Try to match to internal project
        $harvestProject = HarvestProject::where('harvest_id', $data['project']['id'])->first();

        return TimeEntry::updateOrCreate(
            ['harvest_id' => $data['id']],
            [
                'user_id' => $user->id,
                'client_id' => $harvestProject?->client_id,
                'project_id' => $harvestProject?->project_id,
                'harvest_project_id' => $data['project']['id'],
                'harvest_task_id' => $data['task']['id'],
                'hours' => $data['hours'],
                'notes' => $data['notes'],
                'spent_date' => $data['spent_date'],
                'is_running' => $data['is_running'],
                'timer_started_at' => $data['timer_started_at'] ?? null,
                'is_billable' => $data['billable'],
                'is_billed' => $data['is_billed'],
                'hourly_rate' => $data['billable_rate'],
                'cost_rate' => $data['cost_rate'],
            ]
        );
    }

    // Create time entry (start timer)
    public function createTimeEntry(User $user, array $data): array
    {
        $response = $this->client($user)->post('/time_entries', $data);

        if (! $response->successful()) {
            throw new \Exception('Failed to create time entry: '.$response->body());
        }

        $entry = $response->json();
        $this->upsertTimeEntry($entry, $user);

        return $entry;
    }

    // Stop timer
    public function stopTimer(User $user, int $harvestTimeEntryId): array
    {
        $response = $this->client($user)->patch("/time_entries/{$harvestTimeEntryId}/stop");

        if (! $response->successful()) {
            throw new \Exception('Failed to stop timer: '.$response->body());
        }

        $entry = $response->json();
        $this->upsertTimeEntry($entry, $user);

        return $entry;
    }

    // Restart timer
    public function restartTimer(User $user, int $harvestTimeEntryId): array
    {
        $response = $this->client($user)->patch("/time_entries/{$harvestTimeEntryId}/restart");

        if (! $response->successful()) {
            throw new \Exception('Failed to restart timer: '.$response->body());
        }

        $entry = $response->json();
        $this->upsertTimeEntry($entry, $user);

        return $entry;
    }

    // Invoices
    public function syncInvoices(User $user, ?string $from = null, ?string $to = null): int
    {
        $count = 0;
        $page = 1;

        $params = ['per_page' => 100];
        if ($from) {
            $params['from'] = $from;
        }
        if ($to) {
            $params['to'] = $to;
        }

        do {
            $params['page'] = $page;
            $response = $this->client($user)->get('/invoices', $params);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch invoices: '.$response->body());
            }

            $data = $response->json();

            foreach ($data['invoices'] as $invoice) {
                $this->upsertInvoice($invoice);
                $count++;
            }

            $page++;
        } while ($data['total_pages'] >= $page);

        return $count;
    }

    protected function upsertInvoice(array $data): HarvestInvoice
    {
        // Try to match client
        $client = Client::whereHas('harvestProjects', function ($q) use ($data) {
            $q->where('harvest_client_id', $data['client']['id']);
        })->first();

        return HarvestInvoice::updateOrCreate(
            ['harvest_id' => $data['id']],
            [
                'client_id' => $client?->id,
                'harvest_client_id' => $data['client']['id'],
                'number' => $data['number'],
                'subject' => $data['subject'],
                'state' => $data['state'],
                'amount' => $data['amount'],
                'due_amount' => $data['due_amount'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'sent_at' => $data['sent_at'],
                'paid_at' => $data['paid_at'],
                'currency' => $data['currency'],
                'line_items' => $data['line_items'] ?? null,
            ]
        );
    }

    // Create invoice
    public function createInvoice(User $user, int $harvestClientId, array $data): array
    {
        $payload = array_merge([
            'client_id' => $harvestClientId,
        ], $data);

        $response = $this->client($user)->post('/invoices', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create invoice: '.$response->body());
        }

        $invoice = $response->json();
        $this->upsertInvoice($invoice);

        return $invoice;
    }

    // Reports - time by project
    public function getProjectTimeReport(User $user, string $from, string $to): array
    {
        $response = $this->client($user)->get('/reports/time/projects', [
            'from' => $from,
            'to' => $to,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get project report: '.$response->body());
        }

        return $response->json()['results'] ?? [];
    }

    // Reports - time by team
    public function getTeamTimeReport(User $user, string $from, string $to): array
    {
        $response = $this->client($user)->get('/reports/time/team', [
            'from' => $from,
            'to' => $to,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get team report: '.$response->body());
        }

        return $response->json()['results'] ?? [];
    }

    // Calculate profitability snapshot
    public function calculateProfitability(string $periodType, $start, $end, ?int $clientId = null, ?int $projectId = null): ProfitabilitySnapshot
    {
        $query = TimeEntry::whereBetween('spent_date', [$start, $end]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $hoursLogged = $query->sum('hours');
        $hoursBillable = (clone $query)->where('is_billable', true)->sum('hours');

        $revenue = TimeEntry::whereBetween('spent_date', [$start, $end])
            ->where('is_billable', true)
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->selectRaw('SUM(hours * COALESCE(hourly_rate, 0)) as total')
            ->value('total') ?? 0;

        $cost = TimeEntry::whereBetween('spent_date', [$start, $end])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->selectRaw('SUM(hours * COALESCE(cost_rate, 0)) as total')
            ->value('total') ?? 0;

        $profit = $revenue - $cost;
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : null;

        return ProfitabilitySnapshot::create([
            'period_type' => $periodType,
            'period_start' => $start,
            'period_end' => $end,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'hours_logged' => $hoursLogged,
            'hours_billable' => $hoursBillable,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin_percent' => $margin,
        ]);
    }

    public function createProject(User $user, array $data): array
    {
        $payload = [
            'client_id' => $data['harvest_client_id'],
            'name' => $data['name'],
            'is_billable' => $data['is_billable'] ?? true,
            'bill_by' => $data['bill_by'] ?? 'Project',
            'budget_by' => $data['budget_by'] ?? 'project',
        ];

        if (isset($data['code'])) {
            $payload['code'] = $data['code'];
        }
        if (isset($data['hourly_rate'])) {
            $payload['hourly_rate'] = $data['hourly_rate'];
        }
        if (isset($data['budget'])) {
            $payload['budget'] = $data['budget'];
        }
        if (isset($data['notes'])) {
            $payload['notes'] = $data['notes'];
        }
        if (isset($data['is_fixed_fee'])) {
            $payload['is_fixed_fee'] = $data['is_fixed_fee'];
        }
        if (isset($data['fee'])) {
            $payload['fee'] = $data['fee'];
        }
        if (isset($data['budget_is_monthly'])) {
            $payload['budget_is_monthly'] = $data['budget_is_monthly'];
        }

        $response = $this->client($user)->post('/projects', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create Harvest project: '.$response->body());
        }

        $project = $response->json();
        $this->upsertProject($project);

        return $project;
    }

    public function linkProject(int $harvestProjectId, int $projectId, ?int $clientId = null): HarvestProject
    {
        $harvestProject = HarvestProject::where('harvest_id', $harvestProjectId)->firstOrFail();

        $harvestProject->update([
            'project_id' => $projectId,
            'client_id' => $clientId,
        ]);

        return $harvestProject;
    }

    public function getRunningTimers(User $user): array
    {
        return TimeEntry::where('user_id', $user->id)
            ->where('is_running', true)
            ->with(['project', 'client'])
            ->get()
            ->toArray();
    }

    public function getClients(User $user): array
    {
        $clients = [];
        $page = 1;

        do {
            $response = $this->client($user)->get('/clients', [
                'page' => $page,
                'per_page' => 100,
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch clients: '.$response->body());
            }

            $data = $response->json();
            $clients = array_merge($clients, $data['clients']);
            $page++;
        } while ($data['total_pages'] >= $page);

        return $clients;
    }

    public function findClientByName(User $user, string $name): ?array
    {
        $clients = $this->getClients($user);

        foreach ($clients as $client) {
            if (strcasecmp($client['name'], $name) === 0) {
                return $client;
            }
        }

        $normalizedName = strtolower(trim($name));
        foreach ($clients as $client) {
            if (str_contains(strtolower($client['name']), $normalizedName) ||
                str_contains($normalizedName, strtolower($client['name']))) {
                return $client;
            }
        }

        return null;
    }

    public function createClient(User $user, array $data): array
    {
        $payload = [
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ];

        if (isset($data['address'])) {
            $payload['address'] = $data['address'];
        }
        if (isset($data['currency'])) {
            $payload['currency'] = $data['currency'];
        }

        $response = $this->client($user)->post('/clients', $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to create Harvest client: '.$response->body());
        }

        return $response->json();
    }

    public function findOrCreateClient(User $user, Client $localClient): int
    {
        if ($localClient->harvest_client_id) {
            return $localClient->harvest_client_id;
        }

        $harvestClient = $this->findClientByName($user, $localClient->name);

        if ($harvestClient) {
            $localClient->update(['harvest_client_id' => $harvestClient['id']]);

            return $harvestClient['id'];
        }

        $newClient = $this->createClient($user, [
            'name' => $localClient->name,
        ]);

        $localClient->update(['harvest_client_id' => $newClient['id']]);

        return $newClient['id'];
    }
}
