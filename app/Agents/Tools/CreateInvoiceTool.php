<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Invoicing\InvoiceService;

/**
 * Create an invoice for a client.
 *
 * Can create invoices from unbilled time entries, fixed fees, or both.
 */
class CreateInvoiceTool extends BaseTool
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    public function category(): string
    {
        return 'invoicing';
    }

    public function name(): string
    {
        return 'Create Invoice';
    }

    public function description(): string
    {
        return 'Create a draft invoice for a client. Can include unbilled time entries (by specifying time_entry_ids) and/or fixed fee line items. For fixed-fee invoices, just provide client_id and fixed_fees. For time-based invoices, use get-unbilled-time first to see available time entries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the client to invoice (required unless client_name is provided)',
                ],
                'client_name' => [
                    'type' => 'string',
                    'description' => 'Name of the client (alternative to client_id, will look up by name)',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Project ID to associate with the invoice',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Invoice subject line (e.g., "December 2025 Services")',
                ],
                'time_entry_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Array of time entry IDs to include on the invoice',
                ],
                'group_mode' => [
                    'type' => 'string',
                    'enum' => ['individual', 'project', 'task', 'date'],
                    'description' => 'How to group time entries: individual (each entry separate), project (by project), task (by task), or date (by date). Default: individual',
                ],
                'fixed_fees' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'amount' => ['type' => 'number'],
                        ],
                        'required' => ['description', 'amount'],
                    ],
                    'description' => 'Array of fixed fee items to add (each with description and amount)',
                ],
                'notes' => [
                    'type' => 'string',
                    'description' => 'Notes visible to client on the invoice',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_name' => 'nullable|string',
            'project_id' => 'nullable|integer|exists:projects,id',
            'subject' => 'nullable|string|max:255',
            'time_entry_ids' => 'nullable|array',
            'time_entry_ids.*' => 'integer|exists:time_entries,id',
            'group_mode' => 'nullable|string|in:individual,project,task,date',
            'fixed_fees' => 'nullable|array',
            'fixed_fees.*.description' => 'required|string|max:500',
            'fixed_fees.*.amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        // Resolve client by ID or name
        $client = null;
        if (! empty($params['client_id'])) {
            $client = Client::find($params['client_id']);
        }
        if (! $client && ! empty($params['client_name'])) {
            $client = Client::where('name', 'like', "%{$params['client_name']}%")->first();
        }
        if (! $client) {
            return [
                'success' => false,
                'error' => 'Client not found. Provide a valid client_id or client_name.',
            ];
        }

        $project = ! empty($params['project_id']) ? Project::find($params['project_id']) : null;

        $timeEntryIds = $params['time_entry_ids'] ?? [];
        $fixedFees = $params['fixed_fees'] ?? [];
        $groupMode = $params['group_mode'] ?? 'individual';

        // Must have either time entries or fixed fees
        if (empty($timeEntryIds) && empty($fixedFees)) {
            return [
                'success' => false,
                'error' => 'Invoice must include either time entries (time_entry_ids) or fixed fees (fixed_fees).',
            ];
        }

        // Validate time entries belong to this client
        if (! empty($timeEntryIds)) {
            $entries = TimeEntry::whereIn('id', $timeEntryIds)
                ->where('client_id', $client->id)
                ->where('is_billable', true)
                ->where('is_billed', false)
                ->get();

            if ($entries->count() !== count($timeEntryIds)) {
                $foundIds = $entries->pluck('id')->toArray();
                $missing = array_diff($timeEntryIds, $foundIds);

                return [
                    'success' => false,
                    'error' => 'Some time entries are invalid, already billed, or belong to a different client: '.implode(', ', $missing),
                ];
            }

            // Create invoice from time entries
            $invoice = $this->invoiceService->createFromTimeEntries(
                $client,
                $entries,
                [
                    'group_mode' => $groupMode,
                    'subject' => $params['subject'] ?? null,
                    'notes' => $params['notes'] ?? null,
                    'project_id' => $project?->id,
                ]
            );
        } else {
            // Create invoice with just fixed fees
            $invoice = Invoice::createWithUniqueNumber([
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'subject' => $params['subject'] ?? null,
                'notes' => $params['notes'] ?? $client->invoice_notes,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => 0,
                'tax_rate' => $client->default_tax_rate ?? 0,
                'tax_amount' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'amount_due' => 0,
                'issue_date' => now(),
                'due_date' => now()->addDays(30),
                'payment_terms' => $client->payment_terms ?? 'Net 30',
                'currency' => 'USD',
            ]);
        }

        // Add fixed fees
        foreach ($fixedFees as $fee) {
            $this->invoiceService->addFixedFee(
                $invoice,
                $fee['description'],
                $fee['amount'],
                $fee['details'] ?? null,
                $project?->id
            );
        }

        $invoice->refresh();

        return [
            'success' => true,
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'subject' => $invoice->subject,
                'status' => $invoice->status,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'line_count' => $invoice->lines()->count(),
                'url' => "/invoices/{$invoice->id}",
            ],
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'message' => "Invoice #{$invoice->number} created as draft for {$client->name}. Total: \${$invoice->total}",
        ];
    }
}
