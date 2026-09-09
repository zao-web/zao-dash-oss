<?php

namespace App\Agents\Tools;

use App\Models\Contractor;
use App\Models\Email;
use App\Services\Contractor\InvoiceParsingService;
use Illuminate\Support\Facades\Log;

class DetectContractorInvoicesTool extends BaseTool
{
    public function category(): string
    {
        return 'wise';
    }

    public function name(): string
    {
        return 'Detect Contractor Invoices';
    }

    public function description(): string
    {
        return 'Scan recent emails from contractors to detect and create invoices automatically. Matches sender addresses to contractor emails and parses invoice content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days_back' => [
                    'type' => 'integer',
                    'description' => 'Number of days to look back for emails. Default: 7.',
                    'default' => 7,
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, only detect but do not create invoices. Default: false.',
                    'default' => false,
                ],
                'contractor_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Only check emails from this contractor.',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $daysBack = $params['days_back'] ?? 7;
        $dryRun = $params['dry_run'] ?? false;
        $contractorId = $params['contractor_id'] ?? null;

        // Get active contractors
        $contractors = Contractor::active()
            ->when($contractorId, fn ($q) => $q->where('id', $contractorId))
            ->get()
            ->keyBy('email');

        if ($contractors->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No active contractors found',
                'emails_scanned' => 0,
                'detected_count' => 0,
                'created_count' => 0,
                'skipped_count' => 0,
                'detected_invoices' => [],
                'created_invoices' => [],
                'summary' => 'No active contractors found to scan emails for.',
            ];
        }

        $contractorEmails = $contractors->keys()->toArray();

        // Find emails from contractors that haven't been processed for invoices
        $emails = Email::where('received_at', '>=', now()->subDays($daysBack))
            ->whereIn('from_address', $contractorEmails)
            ->where('has_invoice', false)
            ->whereNull('detected_invoice_id')
            ->orderBy('received_at', 'desc')
            ->limit(50)
            ->get();

        $parsingService = new InvoiceParsingService;
        $detected = [];
        $created = [];
        $skipped = [];

        foreach ($emails as $email) {
            $contractor = $contractors->get($email->from_address);

            if (! $contractor) {
                continue;
            }

            // Quick heuristic check first
            if (! $parsingService->isLikelyInvoice($email)) {
                $skipped[] = [
                    'email_id' => $email->id,
                    'subject' => $email->subject,
                    'reason' => 'Not invoice-like content',
                ];

                continue;
            }

            // Parse with AI
            $parsedData = $parsingService->parseEmailForInvoice($email);

            if (! $parsedData || ! ($parsedData['is_invoice'] ?? false)) {
                $skipped[] = [
                    'email_id' => $email->id,
                    'subject' => $email->subject,
                    'reason' => 'AI determined not an invoice',
                    'confidence' => $parsedData['confidence'] ?? null,
                ];

                continue;
            }

            // Low confidence? Skip
            if (($parsedData['confidence'] ?? 0) < 0.7) {
                $skipped[] = [
                    'email_id' => $email->id,
                    'subject' => $email->subject,
                    'reason' => 'Low confidence',
                    'confidence' => $parsedData['confidence'],
                ];

                continue;
            }

            $detected[] = [
                'email_id' => $email->id,
                'contractor' => $contractor->name,
                'subject' => $email->subject,
                'amount' => $parsedData['amount'] ?? 0,
                'currency' => $parsedData['currency'] ?? 'USD',
                'confidence' => $parsedData['confidence'],
            ];

            if (! $dryRun) {
                try {
                    // Link email to contractor
                    $email->update(['contractor_id' => $contractor->id]);

                    // Create the invoice
                    $invoice = $parsingService->createInvoiceFromParsedData(
                        $email,
                        $contractor,
                        $parsedData
                    );

                    $created[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'contractor' => $contractor->name,
                        'amount' => $invoice->amount,
                    ];

                    Log::info('Auto-detected contractor invoice', [
                        'email_id' => $email->id,
                        'contractor_id' => $contractor->id,
                        'invoice_id' => $invoice->id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create invoice from email', [
                        'email_id' => $email->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'emails_scanned' => $emails->count(),
            'detected_count' => count($detected),
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'detected_invoices' => $detected,
            'created_invoices' => $created,
            'summary' => $this->buildSummary($detected, $created, $dryRun),
        ];
    }

    protected function buildSummary(array $detected, array $created, bool $dryRun): string
    {
        if (empty($detected)) {
            return 'No invoices detected in recent contractor emails.';
        }

        $totalAmount = array_sum(array_column($detected, 'amount'));
        $formattedAmount = '$'.number_format($totalAmount, 2);

        if ($dryRun) {
            return sprintf(
                'Detected %d potential invoice(s) totaling %s (dry run - no invoices created).',
                count($detected),
                $formattedAmount
            );
        }

        return sprintf(
            'Created %d invoice(s) totaling %s from contractor emails.',
            count($created),
            $formattedAmount
        );
    }
}
