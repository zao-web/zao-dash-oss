<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateInvoiceTool extends Tool
{
    protected string $name = 'update-invoice';

    protected string $title = 'Update Invoice';

    protected string $description = 'Update an existing invoice\'s fields such as due date, subject, notes, status, or payment terms.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required|exists:invoices,id',
            'subject' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'due_date' => 'nullable|date',
            'issue_date' => 'nullable|date',
            'status' => 'nullable|in:draft,sent,viewed,partial,paid,overdue,cancelled',
            'payment_terms' => 'nullable|string|max:255',
            'po_number' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
        ]);

        $invoice = Invoice::findOrFail($request->get('id'));

        if (! $invoice->isEditable() && $request->get('status') !== 'cancelled') {
            return Response::error("Invoice #{$invoice->number} is {$invoice->status} and cannot be edited.");
        }

        $updates = array_filter([
            'subject' => $request->get('subject'),
            'notes' => $request->get('notes'),
            'internal_notes' => $request->get('internal_notes'),
            'due_date' => $request->get('due_date'),
            'issue_date' => $request->get('issue_date'),
            'status' => $request->get('status'),
            'payment_terms' => $request->get('payment_terms'),
            'po_number' => $request->get('po_number'),
            'recipient_email' => $request->get('recipient_email'),
        ], fn ($value) => ! is_null($value));

        if (empty($updates)) {
            return Response::error('No fields to update. Provide at least one field.');
        }

        $invoice->update($updates);
        $invoice->refresh();

        $changedFields = implode(', ', array_keys($updates));

        return Response::structured([
            'id' => $invoice->id,
            'number' => $invoice->number,
            'client_id' => $invoice->client_id,
            'client_name' => $invoice->client?->name,
            'subject' => $invoice->subject,
            'status' => $invoice->status,
            'total' => (float) $invoice->total,
            'due_date' => $invoice->due_date?->toDateString(),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'message' => "Invoice #{$invoice->number} updated ({$changedFields}).",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Invoice ID to update'),
            'subject' => $schema->string()->description('Invoice subject/title'),
            'notes' => $schema->string()->description('Notes visible to the client'),
            'internal_notes' => $schema->string()->description('Internal notes (not visible to client)'),
            'due_date' => $schema->string()->format('date')->description('Payment due date (YYYY-MM-DD)'),
            'issue_date' => $schema->string()->format('date')->description('Issue date (YYYY-MM-DD)'),
            'status' => $schema->string()->enum(['draft', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'cancelled'])->description('Invoice status'),
            'payment_terms' => $schema->string()->description('Payment terms text'),
            'po_number' => $schema->string()->description('Purchase order number'),
            'recipient_email' => $schema->string()->format('email')->description('Email address for sending invoice'),
        ];
    }
}
