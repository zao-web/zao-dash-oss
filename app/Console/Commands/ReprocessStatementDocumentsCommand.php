<?php

namespace App\Console\Commands;

use App\Jobs\ProcessFinancialDocumentJob;
use App\Models\FinancialDocument;
use Illuminate\Console\Command;

class ReprocessStatementDocumentsCommand extends Command
{
    protected $signature = 'documents:reprocess-statements
        {--year= : Limit to a tax year}
        {--user= : Limit to a user id}
        {--batch= : Limit to an import batch id}
        {--source= : Limit to an import source}
        {--type=all : bank_statement, credit_card_statement, or all}';

    protected $description = 'Queue statement documents for reprocessing with the latest parser.';

    public function handle(): int
    {
        $documentTypes = match ((string) $this->option('type')) {
            'bank_statement' => ['bank_statement'],
            'credit_card_statement' => ['credit_card_statement'],
            default => ['bank_statement', 'credit_card_statement'],
        };

        $documents = FinancialDocument::query()
            ->whereIn('document_type', $documentTypes)
            ->get()
            ->filter(function (FinancialDocument $document): bool {
                $userId = $this->option('user');
                $taxYear = $this->option('year');
                $batchId = $this->option('batch');
                $source = $this->option('source');

                if ($userId !== null && (int) $document->user_id !== (int) $userId) {
                    return false;
                }

                if ($taxYear !== null && (int) data_get($document->extracted_data, 'tax_year') !== (int) $taxYear) {
                    return false;
                }

                if (is_string($batchId) && $batchId !== '' && data_get($document->extracted_data, 'import_batch_id') !== $batchId) {
                    return false;
                }

                if (is_string($source) && $source !== '' && data_get($document->extracted_data, 'import_source') !== $source) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($documents->isEmpty()) {
            $this->info('No matching statement documents found.');

            return self::SUCCESS;
        }

        $documents->each(function (FinancialDocument $document): void {
            $document->update([
                'extracted_data' => $this->preservedMetadata($document),
                'processing_status' => 'pending',
                'processing_notes' => 'Queued for statement reprocessing with the latest parser.',
                'extraction_confidence' => null,
                'needs_review' => true,
                'reviewed_at' => null,
            ]);

            ProcessFinancialDocumentJob::dispatch($document->id);
        });

        $this->info("Queued {$documents->count()} statement document(s) for reprocessing.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function preservedMetadata(FinancialDocument $document): array
    {
        $extractedData = is_array($document->extracted_data) ? $document->extracted_data : [];

        return array_filter([
            'tax_year' => data_get($extractedData, 'tax_year'),
            'import_source' => data_get($extractedData, 'import_source'),
            'archive_relative_path' => data_get($extractedData, 'archive_relative_path'),
            'import_batch_id' => data_get($extractedData, 'import_batch_id'),
            'requested_scope' => data_get($extractedData, 'requested_scope'),
            'inferred_from_path' => data_get($extractedData, 'inferred_from_path'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
