<?php

namespace App\Jobs;

use App\Models\Debt;
use App\Models\FinancialDocument;
use App\Models\TaxObligation;
use App\Services\AI\ClaudeCliService;
use App\Services\PersonalFinance\StatementLineItemParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Process an uploaded financial document.
 *
 * Extracts text from PDFs, classifies the document type using AI,
 * extracts structured data, and auto-links to debts/tax obligations.
 */
class ProcessFinancialDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        protected int $documentId,
    ) {
        $this->onQueue('agents');
    }

    public function handle(ClaudeCliService $claude, StatementLineItemParserService $statementLineItemParserService): void
    {
        $document = FinancialDocument::find($this->documentId);

        if (! $document) {
            Log::warning('ProcessFinancialDocumentJob: Document not found', [
                'document_id' => $this->documentId,
            ]);

            return;
        }

        Log::info('ProcessFinancialDocumentJob: Processing document', [
            'document_id' => $document->id,
            'file_name' => $document->file_name,
            'mime_type' => $document->mime_type,
        ]);

        // Extract text from the document
        $extractedText = $this->extractText($document);

        if (empty($extractedText)) {
            $document->update([
                'processing_status' => 'failed',
                'processing_notes' => 'Could not extract text from document.',
                'needs_review' => true,
            ]);

            return;
        }

        // Use AI to classify and extract structured data
        try {
            $analysis = $this->analyzeWithAI($claude, $extractedText, $document);
            $analysis = $this->enrichStatementAnalysis(
                document: $document,
                analysis: $analysis,
                extractedText: $extractedText,
                statementLineItemParserService: $statementLineItemParserService,
            );
            $resolvedType = $this->resolvedDocumentType($document, $analysis);
            $mergedAnalysis = $this->mergeAnalysis($document, $analysis);
            $confidence = $this->resolvedExtractionConfidence($analysis, $resolvedType);

            $document->update([
                'document_type' => $resolvedType,
                'extracted_data' => $mergedAnalysis,
                'extraction_confidence' => $confidence,
                'processing_status' => 'completed',
                'needs_review' => $confidence < 0.8,
            ]);

            // Auto-link to related records
            $this->autoLinkDocument($document, $analysis);

            Log::info('ProcessFinancialDocumentJob: Document processed successfully', [
                'document_id' => $document->id,
                'document_type' => $analysis['document_type'] ?? 'unknown',
                'confidence' => $analysis['confidence'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessFinancialDocumentJob: AI analysis failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $document->update([
                'processing_status' => 'failed',
                'processing_notes' => 'AI analysis failed: '.$e->getMessage(),
                'needs_review' => true,
            ]);
        }
    }

    /**
     * Extract text content from the document file.
     */
    protected function extractText(FinancialDocument $document): string
    {
        // Try 'private' (persistent on Cloud) first, then fall back to 'local' for legacy records.
        $filePath = null;

        foreach (['private', 'local'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($document->file_path)) {
                    $filePath = Storage::disk($disk)->path($document->file_path);
                    break;
                }
            } catch (\Throwable) {
                // Disk not configured or path-method unsupported on this driver.
            }
        }

        if ($filePath === null || ! file_exists($filePath)) {
            Log::warning('ProcessFinancialDocumentJob: File not found', ['document_id' => $document->id, 'path' => $document->file_path]);

            return '';
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $this->extractPdfText($filePath);
        }

        // For images, return a placeholder — full OCR would need a separate service
        if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
            return '[Image document — manual review required for text extraction]';
        }

        return '';
    }

    /**
     * Extract text from a PDF using smalot/pdfparser.
     */
    protected function extractPdfText(string $filePath): string
    {
        try {
            if (! class_exists(\Smalot\PdfParser\Parser::class)) {
                Log::warning('ProcessFinancialDocumentJob: smalot/pdfparser not installed');

                return '';
            }

            $parser = new \Smalot\PdfParser\Parser;
            $pdf = $parser->parseFile($filePath);

            return $pdf->getText();
        } catch (\Throwable $e) {
            Log::error('ProcessFinancialDocumentJob: PDF parsing failed', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Use Claude to classify the document and extract structured data.
     *
     * @return array<string, mixed>
     */
    protected function analyzeWithAI(ClaudeCliService $claude, string $text, FinancialDocument $document): array
    {
        $systemPrompt = <<<'PROMPT'
You are a financial document analyst. Classify the document and extract structured data.

Return a valid JSON object with these fields:
- document_type: one of "irs_notice", "state_tax_notice", "bank_statement", "credit_card_statement", "tax_return", "efile_acceptance", "filing_acceptance", "extension_acceptance", "tax_transcript", "account_transcript", "collections_letter", "payment_confirmation", "legal_notice", "medical_bill", "w2_packet", "payroll_record", "k1_package", "basis_workpaper", "distribution_ledger", "entity_closure_record", "qbo_export", "general_ledger", "trial_balance", "other"
- confidence: float 0-1 indicating classification confidence
- summary: 1-2 sentence summary of the document
- extracted_fields: object with relevant extracted data

For IRS notices:
- notice_type (e.g., "CP14", "CP501", "CP504", "LT11")
- tax_year
- amount_owed
- deadline (response or payment deadline)
- taxpayer_id_last4 (last 4 digits only)

	For tax returns, transcripts, payment proofs, payroll packets, K-1s, basis workpapers, distribution ledgers, entity closure records, and books exports:
	- tax_year
	- entity_name
	- jurisdiction (federal, oregon, local, or institution name when relevant)
	- amount (if the document is payment-oriented or balance-oriented)

	For tax returns specifically, also extract as many of these as the packet clearly shows:
	- form_types (array, such as ["1040", "OR-40", "1120-S", "1065", "7203"])
	- filing_status
	- adjusted_gross_income
	- federal_taxable_income
	- federal_income_tax
	- oregon_taxable_income
	- oregon_income_tax
	- wages
	- officer_compensation
	- pass_through_income
	- qbi_deduction
	- shareholder_basis
	- shareholder_distributions
	- federal_estimated_payments
	- oregon_estimated_payments
	- federal_overpayment_applied
	- oregon_overpayment_applied
	- capital_loss_carryforward
	- nol_carryforward
	- charitable_carryforward

For bank statements and credit card statements:
- institution
- account_last4
- period_start
- period_end
- opening_balance
- ending_balance
- total_deposits
- total_withdrawals

For collection letters:
- original_creditor
- collection_agency
- account_number_last4
- amount_claimed
- response_deadline

Return ONLY valid JSON, no markdown or explanation.
PROMPT;

        $truncatedText = mb_substr($text, 0, 8000);
        $prompt = "Analyze this financial document and extract structured data:\n\n---\n{$truncatedText}\n---";

        $response = $claude->message(
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            model: 'haiku',
            maxTokens: 1024,
            timeout: 60,
        );

        $content = $response['content'] ?? '';

        // Parse the JSON response
        $jsonMatch = preg_match('/\{[\s\S]*\}/', $content, $matches);
        if ($jsonMatch && ! empty($matches[0])) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return [
            'document_type' => 'other',
            'confidence' => 0.3,
            'summary' => 'Could not reliably parse document content.',
            'extracted_fields' => [],
            'raw_text_excerpt' => mb_substr($text, 0, 500),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function mergeAnalysis(FinancialDocument $document, array $analysis): array
    {
        $existingData = is_array($document->extracted_data) ? $document->extracted_data : [];
        $existingFields = is_array($existingData['extracted_fields'] ?? null) ? $existingData['extracted_fields'] : [];
        $analysisFields = is_array($analysis['extracted_fields'] ?? null) ? $analysis['extracted_fields'] : [];

        $merged = array_merge($existingData, $analysis);
        $merged['extracted_fields'] = array_merge($existingFields, $analysisFields);

        if (! array_key_exists('tax_year', $merged) && array_key_exists('tax_year', $merged['extracted_fields'])) {
            $merged['tax_year'] = $merged['extracted_fields']['tax_year'];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function enrichStatementAnalysis(
        FinancialDocument $document,
        array $analysis,
        string $extractedText,
        StatementLineItemParserService $statementLineItemParserService,
    ): array {
        $resolvedType = $this->resolvedDocumentType($document, $analysis);

        if (! in_array($resolvedType, ['bank_statement', 'credit_card_statement'], true)) {
            return $analysis;
        }

        $statementYear = $this->statementAnalysisYear($document, $analysis);
        $parsedLineItems = $statementLineItemParserService->parse($extractedText, $statementYear);

        $analysis['line_items'] = $parsedLineItems['line_items'];
        $analysis['line_item_summary'] = $parsedLineItems['line_item_summary'];
        $analysis['extracted_fields'] = is_array($analysis['extracted_fields'] ?? null)
            ? $analysis['extracted_fields']
            : [];

        $lineItemDates = collect($parsedLineItems['line_items'])
            ->pluck('transaction_date')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->sort()
            ->values();

        if (! array_key_exists('period_start', $analysis['extracted_fields']) && $lineItemDates->isNotEmpty()) {
            $analysis['extracted_fields']['period_start'] = $lineItemDates->first();
        }

        if (! array_key_exists('period_end', $analysis['extracted_fields']) && $lineItemDates->isNotEmpty()) {
            $analysis['extracted_fields']['period_end'] = $lineItemDates->last();
        }

        if (! array_key_exists('total_deposits', $analysis['extracted_fields'])) {
            $analysis['extracted_fields']['total_deposits'] = data_get($parsedLineItems, 'line_item_summary.parsed_deposits');
        }

        if (! array_key_exists('total_withdrawals', $analysis['extracted_fields'])) {
            $analysis['extracted_fields']['total_withdrawals'] = data_get($parsedLineItems, 'line_item_summary.parsed_withdrawals');
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function resolvedDocumentType(FinancialDocument $document, array $analysis): string
    {
        $analyzedType = (string) ($analysis['document_type'] ?? '');
        $currentType = (string) $document->document_type;

        if ($analyzedType === '' || in_array($analyzedType, ['other', 'unknown'], true)) {
            return $currentType !== '' ? $currentType : 'other';
        }

        return $analyzedType;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function statementAnalysisYear(FinancialDocument $document, array $analysis): ?int
    {
        $candidates = [
            data_get($analysis, 'tax_year'),
            data_get($analysis, 'extracted_fields.tax_year'),
            data_get($document->extracted_data, 'tax_year'),
            data_get($document->extracted_data, 'extracted_fields.tax_year'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        if (preg_match('/\b(20\d{2})\b/', $document->file_name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function resolvedExtractionConfidence(array $analysis, string $resolvedType): float
    {
        $baseConfidence = is_numeric($analysis['confidence'] ?? null)
            ? round((float) $analysis['confidence'], 2)
            : 0.5;

        if (! in_array($resolvedType, ['bank_statement', 'credit_card_statement'], true)) {
            return $baseConfidence;
        }

        $hasPeriod = $this->statementAnalysisHasTextField($analysis, 'period_start')
            && $this->statementAnalysisHasTextField($analysis, 'period_end');
        $hasIdentity = $this->statementAnalysisHasTextField($analysis, 'account_last4')
            || $this->statementAnalysisHasTextField($analysis, 'institution');
        $hasTotals = $this->statementAnalysisHasNumericField($analysis, 'total_deposits')
            && $this->statementAnalysisHasNumericField($analysis, 'total_withdrawals');
        $hasBalances = $this->statementAnalysisHasNumericField($analysis, 'opening_balance')
            && $this->statementAnalysisHasNumericField($analysis, 'ending_balance');
        $usableForMatching = (bool) data_get($analysis, 'line_item_summary.usable_for_matching');

        return match (true) {
            $usableForMatching && $hasPeriod && $hasIdentity && ($hasTotals || $hasBalances) => round(max($baseConfidence, 0.92), 2),
            $hasPeriod && $hasIdentity && ($hasTotals || $hasBalances) => round(max($baseConfidence, 0.85), 2),
            $hasPeriod && $hasIdentity => round(max($baseConfidence, 0.8), 2),
            default => $baseConfidence,
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function statementAnalysisHasTextField(array $analysis, string $key): bool
    {
        $value = data_get($analysis, $key)
            ?? data_get($analysis, "extracted_fields.{$key}");

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function statementAnalysisHasNumericField(array $analysis, string $key): bool
    {
        $value = data_get($analysis, $key)
            ?? data_get($analysis, "extracted_fields.{$key}");

        return is_numeric($value);
    }

    /**
     * Auto-link the document to related debts or tax obligations.
     */
    protected function autoLinkDocument(FinancialDocument $document, array $analysis): void
    {
        $type = $analysis['document_type'] ?? '';
        $fields = $analysis['extracted_fields'] ?? [];

        if ($type === 'irs_notice' && isset($fields['tax_year'])) {
            // Try to link to an existing tax obligation
            $obligation = TaxObligation::where('tax_year', $fields['tax_year'])
                ->whereHas('debt', fn ($q) => $q->where('user_id', $document->user_id))
                ->first();

            if ($obligation) {
                $document->update(['tax_obligation_id' => $obligation->id]);

                // Update the obligation with any new data from the notice
                if (isset($fields['amount_owed'])) {
                    $numericAmount = (float) preg_replace('/[^0-9.]/', '', (string) $fields['amount_owed']);
                    if ($numericAmount > 0 && $obligation->debt) {
                        $obligation->debt->update(['current_balance' => $numericAmount]);
                    }
                }

                if (isset($fields['deadline'])) {
                    $document->update(['response_deadline' => $fields['deadline']]);
                }
            }
        }

        if (in_array($type, ['collections_letter', 'collection_letter', 'medical_bill'])) {
            // Try to match to an existing debt by creditor name
            $creditor = $fields['original_creditor'] ?? $fields['collection_agency'] ?? null;
            if ($creditor) {
                $debt = Debt::where('user_id', $document->user_id)
                    ->where(fn ($q) => $q->where('creditor_name', 'like', "%{$creditor}%")
                        ->orWhere('name', 'like', "%{$creditor}%"))
                    ->first();

                if ($debt) {
                    $document->update(['debt_id' => $debt->id]);
                }
            }
        }
    }
}
