<?php

namespace App\Services\Contractor;

use App\Models\Contractor;
use App\Models\ContractorInvoice;
use App\Models\Email;
use App\Services\AI\ClaudeCliService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceParsingService
{
    protected ClaudeCliService $cli;

    public function __construct()
    {
        $this->cli = new ClaudeCliService;
    }

    /**
     * Parse an email to extract invoice data.
     */
    public function parseEmailForInvoice(Email $email): ?array
    {
        $content = $this->buildParsingContent($email);

        try {
            $parsed = $this->cli->messageJson($content, $this->getSystemPrompt(), 'sonnet', 60);

            if (! $parsed) {
                Log::warning('Invoice parsing returned no data', ['email_id' => $email->id]);

                return null;
            }

            if (! isset($parsed['is_invoice'])) {
                return null;
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('Invoice parsing failed', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Detect if an email looks like an invoice submission.
     */
    public function isLikelyInvoice(Email $email): bool
    {
        $indicators = [
            'invoice',
            'payment',
            'due',
            'amount',
            'billing',
            'services rendered',
            'hours worked',
            'statement',
            'remittance',
        ];

        $subject = strtolower($email->subject ?? '');
        $body = strtolower($email->body_text ?? '');
        $content = $subject.' '.$body;

        $matches = 0;
        foreach ($indicators as $indicator) {
            if (str_contains($content, $indicator)) {
                $matches++;
            }
        }

        // Has PDF attachment or multiple invoice indicators
        $hasPdf = ! empty($email->getPdfAttachments());

        return $matches >= 2 || ($matches >= 1 && $hasPdf);
    }

    /**
     * Create a ContractorInvoice from parsed data.
     */
    public function createInvoiceFromParsedData(
        Email $email,
        Contractor $contractor,
        array $parsedData
    ): ContractorInvoice {
        // Generate invoice number if not found
        $invoiceNumber = $parsedData['invoice_number']
            ?? $this->generateInvoiceNumber($contractor);

        $invoice = ContractorInvoice::create([
            'uuid' => (string) Str::uuid(),
            'contractor_id' => $contractor->id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $parsedData['invoice_date'] ?? now()->toDateString(),
            'due_date' => $parsedData['due_date'] ?? null,
            'amount' => $parsedData['amount'] ?? 0,
            'currency' => $parsedData['currency'] ?? 'USD',
            'description' => $parsedData['description'] ?? $email->subject,
            'line_items' => $parsedData['line_items'] ?? null,
            'attachments' => $email->attachments,
            'status' => ContractorInvoice::STATUS_SUBMITTED,
        ]);

        // Link email to invoice
        $email->update([
            'has_invoice' => true,
            'detected_invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    protected function buildParsingContent(Email $email): string
    {
        $content = "Email Subject: {$email->subject}\n\n";
        $content .= "Email Body:\n{$email->body_text}\n\n";

        if ($email->hasAttachments()) {
            $content .= 'Attachments: ';
            $attachments = array_map(
                fn ($a) => $a['filename'] ?? 'unknown',
                $email->attachments ?? []
            );
            $content .= implode(', ', $attachments);
        }

        return $content;
    }

    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an invoice parsing assistant. Extract invoice details from emails.

Return ONLY a JSON object (no markdown, no explanation) with these fields:
{
  "is_invoice": true/false,
  "confidence": 0.0-1.0,
  "invoice_number": "string or null",
  "invoice_date": "YYYY-MM-DD or null",
  "due_date": "YYYY-MM-DD or null",
  "amount": number or null,
  "currency": "USD" (3-letter code),
  "description": "brief description",
  "line_items": [
    {"description": "string", "quantity": number, "rate": number, "amount": number}
  ] or null
}

If the email is NOT an invoice, return:
{"is_invoice": false, "confidence": 0.9}

Be conservative - only mark as invoice if clearly a payment request.
PROMPT;
    }

    protected function parseAiResponse(string $text): ?array
    {
        // Try to extract JSON from response
        $text = trim($text);

        // Remove potential markdown code blocks
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse AI invoice response', ['text' => $text]);

            return null;
        }

        // Validate required fields
        if (! isset($data['is_invoice'])) {
            return null;
        }

        return $data;
    }

    protected function generateInvoiceNumber(Contractor $contractor): string
    {
        $prefix = strtoupper(substr($contractor->name, 0, 3));
        $date = now()->format('Ymd');
        $count = $contractor->invoices()->whereDate('created_at', today())->count() + 1;

        return "{$prefix}-{$date}-{$count}";
    }
}
