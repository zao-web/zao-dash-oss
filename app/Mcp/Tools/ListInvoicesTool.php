<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListInvoicesTool extends Tool
{
    protected string $name = 'list-invoices';

    protected string $title = 'List Invoices';

    protected string $description = 'List invoices with optional filtering by status or client.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $status = $request->get('status');
        $clientId = $request->get('client_id');
        $limit = $request->get('limit', 50);

        $query = Invoice::with('client')->orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $invoices = $query->limit($limit)->get()->map(fn ($invoice) => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'client' => $invoice->client?->name,
            'client_id' => $invoice->client_id,
            'status' => $invoice->status,
            'amount' => $invoice->amount,
            'amount_due' => $invoice->amount_due,
            'amount_paid' => $invoice->amount_paid,
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'is_overdue' => $invoice->due_date && $invoice->due_date->isPast() && $invoice->status !== 'paid',
            'sent_at' => $invoice->sent_at?->format('Y-m-d'),
            'paid_at' => $invoice->paid_at?->format('Y-m-d'),
        ]);

        $stats = [
            'total_outstanding' => $invoices->where('status', '!=', 'paid')->sum('amount_due'),
            'overdue_count' => $invoices->where('is_overdue', true)->count(),
            'pending_count' => $invoices->whereIn('status', ['draft', 'sent'])->count(),
        ];

        return Response::structured([
            'invoices' => $invoices,
            'total' => $invoices->count(),
            'stats' => $stats,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'sent', 'paid', 'partial', 'overdue', 'cancelled', 'all'])
                ->description('Filter by status'),
            'client_id' => $schema->integer()->description('Filter by client ID'),
            'limit' => $schema->integer()->description('Max invoices to return (default: 50)'),
        ];
    }
}
