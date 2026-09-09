<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class FinancialArchiveImportService
{
    /**
     * @return array{
     *     batch_id: string,
     *     imported_count: int,
     *     queued_count: int,
     *     skipped_count: int,
     *     document_ids: array<int, int>,
     * }
     */
    public function import(
        int $userId,
        UploadedFile $archiveFile,
        ?int $fallbackTaxYear = null,
        ?string $requestedScope = null,
    ): array {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP archive import requires the PHP zip extension.');
        }

        $archivePath = $archiveFile->getRealPath();

        if (! is_string($archivePath) || $archivePath === '') {
            throw new RuntimeException('Could not read the uploaded archive.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($archivePath);

        if ($opened !== true) {
            throw new RuntimeException('Could not open the uploaded archive.');
        }

        $batchId = (string) Str::uuid();
        $importedCount = 0;
        $skippedCount = 0;
        $documentIds = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);

            if (! is_string($entryName) || str_ends_with($entryName, '/')) {
                continue;
            }

            $relativePath = $this->sanitizeRelativePath($entryName);
            $documentType = $relativePath !== null ? $this->inferDocumentType($relativePath) : 'other';
            $taxYear = $relativePath !== null ? ($this->extractTaxYear($relativePath) ?? $fallbackTaxYear) : $fallbackTaxYear;

            if ($relativePath === null || ! $this->isSupportedArchivePath($relativePath)) {
                $skippedCount++;

                continue;
            }

            $contents = $zip->getFromIndex($index);

            if (! is_string($contents) || $contents === '') {
                $skippedCount++;

                continue;
            }

            if ($this->archiveDocumentAlreadyExists($userId, $relativePath, $documentType, $taxYear)) {
                $skippedCount++;

                continue;
            }

            $storagePath = "documents/financial-archive/{$userId}/{$batchId}/{$relativePath}";
            // 'private' is the persistent disk on Laravel Cloud — 'local' is ephemeral and gets wiped on every deploy.
            Storage::disk('private')->put($storagePath, $contents);

            $document = FinancialDocument::create([
                'user_id' => $userId,
                'file_name' => basename($relativePath),
                'file_path' => $storagePath,
                'file_size' => strlen($contents),
                'mime_type' => $this->mimeTypeForPath($relativePath),
                'document_type' => $documentType,
                'processing_status' => 'pending',
                'processing_notes' => 'Imported from uploaded Financials archive.',
                'needs_review' => true,
                'extracted_data' => array_filter([
                    'tax_year' => $taxYear,
                    'import_source' => 'financial_archive_upload',
                    'archive_relative_path' => $relativePath,
                    'import_batch_id' => $batchId,
                    'requested_scope' => in_array($requestedScope, ['personal', 'business'], true) ? $requestedScope : null,
                    'inferred_from_path' => true,
                ], fn (mixed $value): bool => $value !== null),
            ]);

            $documentIds[] = $document->id;
            $importedCount++;
        }

        $zip->close();

        foreach ($documentIds as $documentId) {
            \App\Jobs\ProcessFinancialDocumentJob::dispatch($documentId);
        }

        return [
            'batch_id' => $batchId,
            'imported_count' => $importedCount,
            'queued_count' => count($documentIds),
            'skipped_count' => $skippedCount,
            'document_ids' => $documentIds,
        ];
    }

    protected function archiveDocumentAlreadyExists(int $userId, string $relativePath, string $documentType, ?int $taxYear): bool
    {
        return FinancialDocument::query()
            ->where('user_id', $userId)
            ->where('file_name', basename($relativePath))
            ->where('document_type', $documentType)
            ->get()
            ->contains(function (FinancialDocument $document) use ($relativePath, $taxYear): bool {
                return data_get($document->extracted_data, 'import_source') === 'financial_archive_upload'
                    && data_get($document->extracted_data, 'archive_relative_path') === $relativePath
                    && (int) (data_get($document->extracted_data, 'tax_year') ?? 0) === (int) ($taxYear ?? 0);
            });
    }

    protected function sanitizeRelativePath(string $entryName): ?string
    {
        $normalized = str_replace('\\', '/', trim($entryName));
        $segments = collect(explode('/', $normalized))
            ->filter(fn (string $segment): bool => $segment !== '' && $segment !== '.')
            ->values();

        if ($segments->isEmpty()) {
            return null;
        }

        $safeSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '__MACOSX' || $segment === '.DS_Store') {
                return null;
            }

            $safeSegment = preg_replace('/[^A-Za-z0-9._,() +&-]/', '_', $segment);
            $safeSegment = trim((string) $safeSegment);

            if ($safeSegment === '' || $safeSegment === '.DS_Store') {
                return null;
            }

            $safeSegments[] = $safeSegment;
        }

        return implode('/', $safeSegments);
    }

    protected function isSupportedArchivePath(string $relativePath): bool
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true);
    }

    protected function mimeTypeForPath(string $relativePath): string
    {
        return match (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/pdf',
        };
    }

    protected function inferDocumentType(string $relativePath): string
    {
        $normalized = Str::lower($relativePath);

        return match (true) {
            Str::contains($normalized, ['account transcript']) => 'account_transcript',
            Str::contains($normalized, ['return transcript', 'tax transcript', 'wage and income transcript']) => 'tax_transcript',
            Str::contains($normalized, ['acknowledgement', 'acknowledgment', 'accepted return', 'e-file acceptance', 'efile acceptance']) => 'efile_acceptance',
            Str::contains($normalized, ['eftps', 'direct pay', 'payment confirmation', 'estimated payment', 'tax payment']) => 'payment_confirmation',
            Str::contains($normalized, ['credit card statement', 'card statement', 'amex', 'american express', 'visa', 'mastercard', 'discover', 'quicksilver', 'platinum card']) => 'credit_card_statement',
            Str::contains($normalized, ['w-2', 'w2', 'w-3', 'w3']) => 'w2_packet',
            Str::contains($normalized, ['payroll', '941', '940', 'gusto', 'or-wr', 'oq', '1125-e']) => 'payroll_record',
            Str::contains($normalized, ['k-1', 'k1', 'schedule e']) => 'k1_package',
            Str::contains($normalized, ['7203', 'basis', 'qbi', '8995', '8995-a', 'loss limitation']) => 'basis_workpaper',
            Str::contains($normalized, ['distribution', 'owner transfer', 'shareholder basis']) => 'distribution_ledger',
            Str::contains($normalized, ['trial balance']) => 'trial_balance',
            Str::contains($normalized, ['general ledger']) => 'general_ledger',
            Str::contains($normalized, ['quickbooks', 'qbo', 'profit and loss', 'p&l', 'balance sheet']) => 'qbo_export',
            Str::contains($normalized, ['bank statements', 'bank statement', 'wellsfargo', 'checking', 'savings']) => 'bank_statement',
            Str::contains($normalized, ['tax return', 'return packet', '1040', '1120-s', '1120s', '1065', 'or-40']) => 'tax_return',
            Str::contains($normalized, ['irs notice', 'cp14', 'cp501', 'cp504', 'lt11']) => 'irs_notice',
            default => 'other',
        };
    }

    protected function extractTaxYear(string $relativePath): ?int
    {
        preg_match_all('/\b(20\d{2})\b/', $relativePath, $matches);

        foreach ($matches[1] ?? [] as $match) {
            return (int) $match;
        }

        return null;
    }
}
