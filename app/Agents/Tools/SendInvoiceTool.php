<?php

namespace App\Agents\Tools;

use App\Mail\InvoiceSent;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use App\Services\Invoicing\PdfInvoiceGenerator;
use App\Services\PayPal\PayPalService;
use Illuminate\Support\Facades\Mail;

/**
 * Send an invoice to the client.
 *
 * This triggers PDF generation, PayPal invoice creation, email delivery,
 * and reminder scheduling.
 */
class SendInvoiceTool extends BaseTool
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected PdfInvoiceGenerator $pdfGenerator,
        protected PayPalService $paypalService
    ) {}

    public function category(): string
    {
        return 'invoicing';
    }

    public function name(): string
    {
        return 'Send Invoice';
    }

    public function description(): string
    {
        return 'Send a draft invoice to the client. This generates the PDF, creates a PayPal payment link, sends the email, and schedules reminders. Only works for draft invoices.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => [
                    'type' => 'integer',
                    'description' => 'ID of the invoice to send (required)',
                ],
            ],
            'required' => ['invoice_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'invoice_id' => 'required|integer|exists:invoices,id',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'high';
    }

    public function execute(array $params): array
    {
        $invoice = Invoice::with(['client', 'lines'])->findOrFail($params['invoice_id']);

        // Verify invoice is in draft status
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return [
                'success' => false,
                'error' => "Invoice #{$invoice->number} is not in draft status. Current status: {$invoice->status}",
            ];
        }

        // Verify invoice has line items
        if ($invoice->lines->isEmpty()) {
            return [
                'success' => false,
                'error' => "Invoice #{$invoice->number} has no line items. Cannot send an empty invoice.",
            ];
        }

        // Verify client has billing email
        $billingEmail = $invoice->client->billing_email ?? $invoice->client->email;
        if (! $billingEmail) {
            return [
                'success' => false,
                'error' => "Client {$invoice->client->name} has no email address configured.",
            ];
        }

        try {
            // Generate PDF
            $this->pdfGenerator->generateAndStore($invoice);

            // Create PayPal invoice if configured
            $paypalInvoiceId = null;
            if (config('services.paypal.client_id')) {
                try {
                    $paypalInvoiceId = $this->paypalService->createInvoice($invoice);
                    if ($paypalInvoiceId) {
                        $invoice->update(['paypal_invoice_id' => $paypalInvoiceId]);
                    }
                } catch (\Exception $e) {
                    // Log but don't fail - PayPal is optional
                    \Log::warning('PayPal invoice creation failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Send email
            Mail::to($billingEmail)->send(new InvoiceSent($invoice));

            // Mark as sent and schedule reminders
            $this->invoiceService->markAsSent($invoice);

            return [
                'success' => true,
                'invoice' => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'total' => $invoice->total,
                    'status' => 'sent',
                ],
                'sent_to' => $billingEmail,
                'paypal_invoice_created' => ! empty($paypalInvoiceId),
                'message' => "Invoice #{$invoice->number} sent to {$billingEmail}",
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to send invoice: '.$e->getMessage(),
            ];
        }
    }
}
