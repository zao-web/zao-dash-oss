<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SowProvisioningService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{
     *     create_invoices?: bool,
     *     workspace_id?: string|null,
     *     channel_id?: string|null,
     *     link_to_channel?: bool
     * }  $options
     * @return array<string, mixed>
     */
    public function provision(array $data, array $options = []): array
    {
        $createInvoices = $options['create_invoices'] ?? true;
        $linkToChannel = $options['link_to_channel'] ?? false;
        $workspaceId = $options['workspace_id'] ?? null;
        $channelId = $options['channel_id'] ?? null;

        return DB::transaction(function () use ($data, $createInvoices, $linkToChannel, $workspaceId, $channelId): array {
            $client = Client::query()
                ->whereRaw('LOWER(name) = ?', [strtolower((string) data_get($data, 'client.name'))])
                ->first();

            if (! $client) {
                $client = Client::create([
                    'name' => data_get($data, 'client.name'),
                    'slug' => $this->uniqueClientSlug((string) data_get($data, 'client.name')),
                    'website' => data_get($data, 'client.website'),
                    'description' => data_get($data, 'client.description'),
                    'status' => 'active',
                ]);
            }

            $contactsCreated = $this->createContacts($client, (array) ($data['contacts'] ?? []));

            $project = Project::create([
                'client_id' => $client->id,
                'name' => data_get($data, 'project.name'),
                'slug' => $this->uniqueProjectSlug((string) data_get($data, 'project.name')),
                'description' => data_get($data, 'project.description'),
                'type' => data_get($data, 'project.type'),
                'status' => 'active',
                'budget' => data_get($data, 'project.budget'),
                'start_date' => $this->parseDate(data_get($data, 'project.start_date')),
                'end_date' => $this->parseDate(data_get($data, 'project.end_date')),
            ]);

            $milestonesCreated = 0;
            $tasksCreated = 0;

            foreach ((array) ($data['milestones'] ?? []) as $milestoneData) {
                $milestone = Milestone::create([
                    'project_id' => $project->id,
                    'name' => $milestoneData['name'],
                    'description' => $milestoneData['description'] ?? null,
                    'status' => 'pending',
                    'due_date' => $this->parseDate($milestoneData['due_date'] ?? null),
                ]);
                $milestonesCreated++;

                foreach ((array) ($milestoneData['tasks'] ?? []) as $taskData) {
                    Task::create([
                        'project_id' => $project->id,
                        'milestone_id' => $milestone->id,
                        'title' => $taskData['title'],
                        'description' => $taskData['description'] ?? null,
                        'priority' => $taskData['priority'] ?? 'medium',
                        'estimated_hours' => $taskData['estimated_hours'] ?? null,
                        'due_date' => $this->parseDate($taskData['due_date'] ?? null),
                        'status' => 'pending',
                        'source' => 'sow_import',
                        'metadata' => [
                            'imported_at' => now()->toIso8601String(),
                            'ai_generated' => true,
                        ],
                    ]);
                    $tasksCreated++;

                    foreach ((array) ($taskData['subtasks'] ?? []) as $subtask) {
                        Task::create([
                            'project_id' => $project->id,
                            'milestone_id' => $milestone->id,
                            'title' => "[{$taskData['title']}] {$subtask['title']}",
                            'description' => $subtask['description'] ?? null,
                            'priority' => $taskData['priority'] ?? 'medium',
                            'due_date' => $this->parseDate($subtask['due_date'] ?? null),
                            'status' => 'pending',
                            'source' => 'sow_import',
                            'metadata' => [
                                'imported_at' => now()->toIso8601String(),
                                'ai_generated' => true,
                                'is_subtask' => true,
                                'parent_task_title' => $taskData['title'],
                            ],
                        ]);
                        $tasksCreated++;
                    }
                }
            }

            $invoicesCreated = $createInvoices
                ? $this->createInvoices($client, $project, (array) ($data['invoices'] ?? []))
                : 0;

            $recurringInvoiceConfigured = $this->configureRecurringInvoice($client, $project, (array) ($data['billing'] ?? []));

            $channelLink = null;
            if ($linkToChannel && $workspaceId && $channelId) {
                $channelLink = $this->linkSlackChannel($workspaceId, $channelId, $client, $project);
            }

            return [
                'project_id' => $project->id,
                'client_id' => $client->id,
                'summary' => [
                    'client' => $client->wasRecentlyCreated ? 'created' : 'existing',
                    'contacts_created' => $contactsCreated,
                    'milestones_created' => $milestonesCreated,
                    'tasks_created' => $tasksCreated,
                    'invoices_created' => $invoicesCreated,
                    'recurring_invoice_configured' => $recurringInvoiceConfigured,
                ],
                'channel_link' => $channelLink,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     */
    protected function createContacts(Client $client, array $contacts): int
    {
        $created = 0;
        $teamEmails = User::query()
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower((string) $email))
            ->all();

        foreach ($contacts as $contactData) {
            $email = strtolower((string) ($contactData['email'] ?? ''));

            if ($email === '' || in_array($email, $teamEmails, true)) {
                continue;
            }

            $exists = ClientContact::query()
                ->where('client_id', $client->id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($exists) {
                continue;
            }

            ClientContact::create([
                'client_id' => $client->id,
                'name' => $contactData['name'],
                'email' => $contactData['email'],
                'role' => $contactData['role'] ?? null,
                'phone' => $contactData['phone'] ?? null,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $invoices
     */
    protected function createInvoices(Client $client, Project $project, array $invoices): int
    {
        $created = 0;

        foreach ($invoices as $invoiceData) {
            $items = $this->normalizeInvoiceItems($invoiceData);

            if ($items === []) {
                continue;
            }

            $issueDate = $this->parseDate($invoiceData['issue_date'] ?? null) ?? now()->startOfDay();
            $dueDate = $this->parseDate($invoiceData['due_date'] ?? null)
                ?? $issueDate->copy()->addDays((int) ($invoiceData['due_days'] ?? 30));

            $invoice = Invoice::createWithUniqueNumber([
                'client_id' => $client->id,
                'project_id' => $project->id,
                'subject' => $invoiceData['subject'] ?? "Invoice for {$client->name}",
                'notes' => $invoiceData['description'] ?? null,
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency' => 'USD',
                'tax_rate' => 0,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'amount_due' => 0,
            ]);

            foreach ($items as $index => $item) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'project_id' => $project->id,
                    'type' => $item['type'] ?? InvoiceLine::TYPE_FIXED,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit' => 'unit',
                    'taxable' => true,
                    'sort_order' => $index + 1,
                ]);
            }

            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    protected function configureRecurringInvoice(Client $client, Project $project, array $billing): bool
    {
        $recurringInvoice = $billing['recurring_invoice'] ?? null;

        if (! is_array($recurringInvoice) || ! ($recurringInvoice['enabled'] ?? false) || empty($recurringInvoice['amount'])) {
            return false;
        }

        $client->update([
            'recurring_invoice_enabled' => true,
            'recurring_invoice_amount' => $recurringInvoice['amount'],
            'recurring_invoice_day' => max(1, min(28, (int) ($recurringInvoice['day'] ?? 1))),
            'recurring_invoice_auto_send' => (bool) ($recurringInvoice['auto_send'] ?? false),
            'recurring_invoice_description' => $recurringInvoice['description'] ?? $project->name,
            'recurring_invoice_project_id' => $project->id,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     * @return array<int, array{description: string, quantity: float|int, unit_price: float|int, type?: string}>
     */
    protected function normalizeInvoiceItems(array $invoiceData): array
    {
        $items = $invoiceData['items'] ?? null;

        if (is_array($items) && $items !== []) {
            return collect($items)
                ->filter(fn ($item) => is_array($item) && ! empty($item['description']))
                ->map(fn (array $item): array => [
                    'description' => (string) $item['description'],
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'type' => $item['type'] ?? InvoiceLine::TYPE_FIXED,
                ])
                ->filter(fn (array $item) => $item['unit_price'] >= 0)
                ->values()
                ->all();
        }

        if (isset($invoiceData['amount']) && is_numeric($invoiceData['amount'])) {
            return [[
                'description' => (string) ($invoiceData['description'] ?? $invoiceData['subject'] ?? 'Project fee'),
                'quantity' => 1,
                'unit_price' => (float) $invoiceData['amount'],
                'type' => InvoiceLine::TYPE_FIXED,
            ]];
        }

        return [];
    }

    protected function linkSlackChannel(string $workspaceId, string $channelId, Client $client, Project $project): ?array
    {
        $workspace = SlackWorkspace::query()
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $workspace) {
            return null;
        }

        $channel = SlackChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $channel) {
            return null;
        }

        $channel->update([
            'client_id' => $client->id,
            'classification' => 'client',
            'monitoring_enabled' => true,
            'is_monitored' => true,
        ]);

        $client->update([
            'slack_channel_id' => $channel->id,
        ]);

        $project->update([
            'slack_channel_id' => $channel->id,
        ]);

        return [
            'workspace_id' => $workspace->workspace_id,
            'channel_id' => $channel->channel_id,
            'channel_name' => $channel->channel_name,
        ];
    }

    protected function uniqueClientSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (Client::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        return $slug;
    }

    protected function uniqueProjectSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        return $slug;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
