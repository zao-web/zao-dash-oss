<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateInvoiceTool extends Tool
{
    protected string $name = 'create-invoice';

    protected string $title = 'Create Invoice';

    protected string $description = 'Create a new invoice for a client with line items.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'subject' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'due_days' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.type' => 'nullable|in:time,fixed,expense,discount',
        ]);

        $client = Client::findOrFail($request->get('client_id'));

        $invoice = DB::transaction(function () use ($client, $request) {
            $invoice = Invoice::createWithUniqueNumber([
                'client_id' => $client->id,
                'project_id' => $request->get('project_id'),
                'subject' => $request->get('subject') ?? "Invoice for {$client->name}",
                'notes' => $request->get('notes'),
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now(),
                'due_date' => now()->addDays($request->get('due_days', 30)),
                'currency' => 'USD',
                'tax_rate' => 0,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'amount_due' => 0,
            ]);

            $sortOrder = 1;
            foreach ($request->get('items') as $item) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'type' => $item['type'] ?? InvoiceLine::TYPE_FIXED,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit' => 'unit',
                    'taxable' => true,
                    'sort_order' => $sortOrder++,
                ]);
            }

            // Refresh to get recalculated totals
            $invoice->refresh();

            return $invoice;
        });

        return Response::structured([
            'id' => $invoice->id,
            'number' => $invoice->number,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'subject' => $invoice->subject,
            'status' => $invoice->status,
            'subtotal' => (float) $invoice->subtotal,
            'total' => (float) $invoice->total,
            'due_date' => $invoice->due_date->toDateString(),
            'line_count' => $invoice->lines()->count(),
            'public_url' => $invoice->public_url,
            'message' => "Invoice #{$invoice->number} created for '{$client->name}' with total \${$invoice->total}.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client ID to create invoice for'),
            'project_id' => $schema->integer()->description('Optional project ID to associate'),
            'subject' => $schema->string()->description('Invoice subject/title'),
            'notes' => $schema->string()->description('Notes to include on invoice'),
            'due_days' => $schema->integer()->description('Days until due (default: 30)'),
            'items' => $schema->array()->required()->items(
                $schema->object([
                    'description' => $schema->string()->required()->description('Line item description'),
                    'quantity' => $schema->number()->required()->description('Quantity'),
                    'unit_price' => $schema->number()->required()->description('Price per unit'),
                    'type' => $schema->string()->enum(['time', 'fixed', 'expense', 'discount'])->description('Line type (default: fixed)'),
                ])
            )->description('Array of invoice line items'),
        ];
    }
}
