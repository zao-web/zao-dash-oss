<?php

namespace App\Agents\Tools;

use App\Models\Invoice;

/**
 * List invoices with optional filtering.
 *
 * Helps agents find and review existing invoices.
 */
class ListInvoicesTool extends BaseTool
{
    public function category(): string
    {
        return 'invoicing';
    }

    public function name(): string
    {
        return 'List Invoices';
    }

    public function description(): string
    {
        return 'List invoices with optional filters for client, status, or date range. Returns summary information about each invoice.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'client_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by client ID',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'cancelled'],
                    'description' => 'Filter by invoice status',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Filter invoices issued on or after this date (YYYY-MM-DD)',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Filter invoices issued on or before this date (YYYY-MM-DD)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of invoices to return (default: 20, max: 100)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'client_id' => 'nullable|integer|exists:clients,id',
            'status' => 'nullable|string|in:draft,sent,viewed,partial,paid,overdue,cancelled',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $query = Invoice::with(['client:id,name'])
            ->orderBy('created_at', 'desc');

        if (! empty($params['client_id'])) {
            $query->where('client_id', $params['client_id']);
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['from_date'])) {
            $query->where('issue_date', '>=', $params['from_date']);
        }

        if (! empty($params['to_date'])) {
            $query->where('issue_date', '<=', $params['to_date']);
        }

        $limit = min($params['limit'] ?? 20, 100);
        $invoices = $query->limit($limit)->get();

        // Calculate summary stats
        $stats = [
            'total_count' => $invoices->count(),
            'total_amount' => round($invoices->sum('total'), 2),
            'total_outstanding' => round($invoices->sum('amount_due'), 2),
            'by_status' => $invoices->groupBy('status')->map->count()->toArray(),
        ];

        return [
            'invoices' => $invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'number' => $inv->number,
                'client' => $inv->client?->name,
                'subject' => $inv->subject,
                'status' => $inv->status,
                'total' => $inv->total,
                'amount_due' => $inv->amount_due,
                'issue_date' => $inv->issue_date->format('Y-m-d'),
                'due_date' => $inv->due_date->format('Y-m-d'),
                'days_overdue' => $inv->days_overdue,
                'url' => "/invoices/{$inv->id}",
            ])->values()->all(),
            'stats' => $stats,
            'filters_applied' => array_filter([
                'client_id' => $params['client_id'] ?? null,
                'status' => $params['status'] ?? null,
                'from_date' => $params['from_date'] ?? null,
                'to_date' => $params['to_date'] ?? null,
            ]),
        ];
    }
}
