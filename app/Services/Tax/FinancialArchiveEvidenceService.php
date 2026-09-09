<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class FinancialArchiveEvidenceService
{
    /**
     * @return array{
     *     is_available: bool,
     *     root: string|null,
     *     coverage_status: string,
     *     current_year_coverage_status: string,
     *     next_action: string,
     *     uploaded_document_count: int,
     *     tax_return_count: int,
     *     prior_business_return_years: array<int, int>,
     *     prior_personal_return_years: array<int, int>,
     *     bank_statement_years: array<int, int>,
     *     payroll_document_count: int,
     *     payroll_document_paths: array<int, string>,
     *     current_year_document_count: int,
     *     current_year_missing_categories: array<int, string>,
     *     current_year_document_evidence: array<string, mixed>,
     *     archive_gaps: array<int, string>,
     * }
     */
    public function summarize(?int $year = null, ?int $userId = null): array
    {
        $root = $this->resolveRoot();
        $uploadedArchive = $userId !== null
            ? $this->uploadedArchiveSummary($userId, $year)
            : $this->emptyUploadedArchiveSummary();

        if ($root === null && ! $uploadedArchive['is_available']) {
            return [
                'is_available' => false,
                'root' => null,
                'coverage_status' => 'missing',
                'current_year_coverage_status' => 'missing',
                'next_action' => 'Upload prior returns, bank statements, and payroll packets into Documents so Tax Office can use them as historical evidence.',
                'uploaded_document_count' => 0,
                'tax_return_count' => 0,
                'prior_business_return_years' => [],
                'prior_personal_return_years' => [],
                'bank_statement_years' => [],
                'payroll_document_count' => 0,
                'payroll_document_paths' => [],
                'current_year_document_count' => 0,
                'current_year_missing_categories' => [],
                'current_year_document_evidence' => $this->emptyCurrentYearEvidence($year),
                'archive_gaps' => ['financial archive root'],
            ];
        }

        $filesystemArchive = $root !== null
            ? $this->filesystemArchiveSummary($root, $year)
            : $this->emptyFilesystemArchiveSummary($year);
        $priorBusinessReturnYears = $this->mergeYears(
            $filesystemArchive['prior_business_return_years'],
            $uploadedArchive['prior_business_return_years'],
        );
        $priorPersonalReturnYears = $this->mergeYears(
            $filesystemArchive['prior_personal_return_years'],
            $uploadedArchive['prior_personal_return_years'],
        );
        $bankStatementYears = $this->mergeYears(
            $filesystemArchive['bank_statement_years'],
            $uploadedArchive['bank_statement_years'],
        );
        $payrollDocumentPaths = array_values(array_unique(array_merge(
            $filesystemArchive['payroll_document_paths'],
            $uploadedArchive['payroll_document_paths'],
        )));

        $archiveGaps = $this->archiveGaps(
            $priorBusinessReturnYears,
            $priorPersonalReturnYears,
            $bankStatementYears,
            $payrollDocumentPaths,
        );

        return [
            'is_available' => true,
            'root' => $root,
            'coverage_status' => $this->coverageStatus($archiveGaps, $payrollDocumentPaths),
            'current_year_coverage_status' => $filesystemArchive['current_year_document_evidence']['coverage_status'],
            'next_action' => $this->nextAction(
                archiveGaps: $archiveGaps,
                payrollDocumentPaths: $payrollDocumentPaths,
                hasUploadedArchive: $uploadedArchive['is_available'],
                hasFilesystemArchive: $root !== null,
            ),
            'uploaded_document_count' => $uploadedArchive['document_count'],
            'tax_return_count' => $filesystemArchive['tax_return_count'] + $uploadedArchive['tax_return_count'],
            'prior_business_return_years' => $priorBusinessReturnYears,
            'prior_personal_return_years' => $priorPersonalReturnYears,
            'bank_statement_years' => $bankStatementYears,
            'payroll_document_count' => count($payrollDocumentPaths),
            'payroll_document_paths' => $payrollDocumentPaths,
            'current_year_document_count' => $filesystemArchive['current_year_document_evidence']['document_count'],
            'current_year_missing_categories' => $filesystemArchive['current_year_document_evidence']['missing_core_categories'],
            'current_year_document_evidence' => $filesystemArchive['current_year_document_evidence'],
            'archive_gaps' => $archiveGaps,
        ];
    }

    /**
     * @return array{
     *     tax_return_count: int,
     *     prior_business_return_years: array<int, int>,
     *     prior_personal_return_years: array<int, int>,
     *     bank_statement_years: array<int, int>,
     *     payroll_document_paths: array<int, string>,
     *     current_year_document_evidence: array<string, mixed>,
     * }
     */
    protected function filesystemArchiveSummary(string $root, ?int $year): array
    {
        $taxReturnRoot = $root.'/Tax Returns';
        $bankStatementRoot = $root.'/Bank Statements';

        $taxReturnFiles = $this->filesWithin($taxReturnRoot);
        $bankStatementFiles = $this->filesWithin($bankStatementRoot);
        $allFiles = $this->filesWithin($root);

        return [
            'tax_return_count' => count($taxReturnFiles),
            'prior_business_return_years' => $this->collectYears(
                array_filter($taxReturnFiles, fn (string $path): bool => $this->isBusinessReturn($path, $taxReturnRoot)),
                $year,
            ),
            'prior_personal_return_years' => $this->collectYears(
                array_filter($taxReturnFiles, fn (string $path): bool => ! $this->isBusinessReturn($path, $taxReturnRoot)),
                $year,
            ),
            'bank_statement_years' => $this->collectYears($bankStatementFiles, $year),
            'payroll_document_paths' => array_values(array_map(
                fn (string $path): string => $this->relativePath($root, $path),
                array_filter($allFiles, fn (string $path): bool => $this->isPayrollDocument($path)),
            )),
            'current_year_document_evidence' => $this->currentYearDocumentEvidence($root, $allFiles, $year),
        ];
    }

    /**
     * @return array{
     *     tax_return_count: int,
     *     prior_business_return_years: array<int, int>,
     *     prior_personal_return_years: array<int, int>,
     *     bank_statement_years: array<int, int>,
     *     payroll_document_paths: array<int, string>,
     *     current_year_document_evidence: array<string, mixed>,
     * }
     */
    protected function emptyFilesystemArchiveSummary(?int $year): array
    {
        return [
            'tax_return_count' => 0,
            'prior_business_return_years' => [],
            'prior_personal_return_years' => [],
            'bank_statement_years' => [],
            'payroll_document_paths' => [],
            'current_year_document_evidence' => $this->emptyCurrentYearEvidence($year),
        ];
    }

    /**
     * @return array{
     *     is_available: bool,
     *     document_count: int,
     *     tax_return_count: int,
     *     prior_business_return_years: array<int, int>,
     *     prior_personal_return_years: array<int, int>,
     *     bank_statement_years: array<int, int>,
     *     payroll_document_paths: array<int, string>,
     * }
     */
    protected function uploadedArchiveSummary(int $userId, ?int $targetYear): array
    {
        $documents = FinancialDocument::query()
            ->where('user_id', $userId)
            ->whereIn('document_type', ['tax_return', 'bank_statement', 'payroll_record', 'w2_packet'])
            ->latest('id')
            ->get()
            ->reject(fn (FinancialDocument $document): bool => $this->shouldIgnoreDocument($document))
            ->values();

        $taxReturnDocuments = $documents
            ->filter(fn (FinancialDocument $document): bool => $document->document_type === 'tax_return')
            ->values();
        $bankStatementDocuments = $documents
            ->filter(fn (FinancialDocument $document): bool => $document->document_type === 'bank_statement')
            ->values();
        $payrollDocuments = $documents
            ->filter(fn (FinancialDocument $document): bool => in_array($document->document_type, ['payroll_record', 'w2_packet'], true))
            ->values();

        return [
            'is_available' => $documents->isNotEmpty(),
            'document_count' => $documents->count(),
            'tax_return_count' => $taxReturnDocuments->count(),
            'prior_business_return_years' => $this->collectDocumentYears(
                $taxReturnDocuments->filter(fn (FinancialDocument $document): bool => $this->isBusinessReturnDocument($document)),
                $targetYear,
            ),
            'prior_personal_return_years' => $this->collectDocumentYears(
                $taxReturnDocuments->reject(fn (FinancialDocument $document): bool => $this->isBusinessReturnDocument($document)),
                $targetYear,
            ),
            'bank_statement_years' => $this->collectDocumentYears($bankStatementDocuments, $targetYear),
            'payroll_document_paths' => $payrollDocuments
                ->map(fn (FinancialDocument $document): string => $document->file_name)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     is_available: bool,
     *     document_count: int,
     *     tax_return_count: int,
     *     prior_business_return_years: array<int, int>,
     *     prior_personal_return_years: array<int, int>,
     *     bank_statement_years: array<int, int>,
     *     payroll_document_paths: array<int, string>,
     * }
     */
    protected function emptyUploadedArchiveSummary(): array
    {
        return [
            'is_available' => false,
            'document_count' => 0,
            'tax_return_count' => 0,
            'prior_business_return_years' => [],
            'prior_personal_return_years' => [],
            'bank_statement_years' => [],
            'payroll_document_paths' => [],
        ];
    }

    /**
     * @param  array<int, string>  $allFiles
     * @return array<string, mixed>
     */
    protected function currentYearDocumentEvidence(string $root, array $allFiles, ?int $year): array
    {
        if ($year === null) {
            return $this->emptyCurrentYearEvidence(null);
        }

        $categoryDefinitions = $this->currentYearCategoryDefinitions();
        $currentYearFiles = array_values(array_filter(
            $allFiles,
            fn (string $path): bool => $this->fileMatchesYear($path, $year),
        ));

        $categories = collect($categoryDefinitions)
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => $this->currentYearCategorySummary($root, $currentYearFiles, $definition['label'], $definition['keywords']),
            ])
            ->all();

        $uniqueDocuments = collect($categories)
            ->flatMap(fn (array $category): array => $category['documents'])
            ->unique('path')
            ->values();

        $missingCoreCategories = collect($categories)
            ->filter(fn (array $category): bool => $category['count'] === 0)
            ->map(fn (array $category): string => $category['label'])
            ->values()
            ->all();

        $reviewedCoreCategoryCount = collect($categories)
            ->filter(fn (array $category): bool => $category['count'] > 0)
            ->count();

        $coverageStatus = match (true) {
            $uniqueDocuments->isEmpty() => 'missing',
            $reviewedCoreCategoryCount >= 5 => 'current',
            default => 'partial',
        };

        return [
            'tax_year' => $year,
            'document_count' => $uniqueDocuments->count(),
            'reviewed_document_count' => $uniqueDocuments->count(),
            'needs_review_count' => 0,
            'coverage_status' => $coverageStatus,
            'next_action' => $this->currentYearNextAction($coverageStatus, $missingCoreCategories),
            'missing_core_categories' => $missingCoreCategories,
            'return_packet_count' => $categories['return_packets']['count'],
            'acceptance_document_count' => $categories['acceptance_records']['count'],
            'payment_confirmation_count' => $categories['payment_confirmations']['count'],
            'transcript_document_count' => $categories['transcripts']['count'],
            'payroll_document_count' => $categories['payroll_packets']['count'],
            'k1_document_count' => $categories['k1_packages']['count'],
            'basis_document_count' => $categories['basis_workpapers']['count'],
            'distribution_document_count' => $categories['distribution_ledgers']['count'],
            'bank_statement_count' => $categories['bank_statements']['count'],
            'books_export_count' => $categories['books_exports']['count'],
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, array{label: string, keywords: array<int, string>}>
     */
    protected function currentYearCategoryDefinitions(): array
    {
        return [
            'return_packets' => [
                'label' => 'Return packets',
                'keywords' => ['tax return', 'return packet', '1040', '1120-s', '1120s', '1065', 'or-40'],
            ],
            'acceptance_records' => [
                'label' => 'Acceptance records',
                'keywords' => ['accepted return', 'acknowledgement', 'acknowledgment', 'e-file acceptance', 'efile acceptance'],
            ],
            'payment_confirmations' => [
                'label' => 'Payment confirmations',
                'keywords' => ['payment confirmation', 'eftps', 'direct pay', 'estimated payment', 'tax payment'],
            ],
            'transcripts' => [
                'label' => 'Transcripts',
                'keywords' => ['account transcript', 'return transcript', 'tax transcript', 'wage and income transcript'],
            ],
            'payroll_packets' => [
                'label' => 'Payroll and W-2 packets',
                'keywords' => ['w-2', 'w2', 'w-3', 'w3', 'payroll', '941', '940', '1125-e', 'officer compensation', 'gusto', 'or-wr', 'oq'],
            ],
            'k1_packages' => [
                'label' => 'K-1 packages',
                'keywords' => ['k-1', 'k1', 'schedule e'],
            ],
            'basis_workpapers' => [
                'label' => 'Basis and QBI workpapers',
                'keywords' => ['7203', 'basis', 'qbi', '8995', '8995-a', 'loss limitation'],
            ],
            'distribution_ledgers' => [
                'label' => 'Distribution ledgers',
                'keywords' => ['distribution', 'owner transfer', 'shareholder basis'],
            ],
            'bank_statements' => [
                'label' => 'Bank statements',
                'keywords' => ['bank statement', 'statement', 'checking', 'savings', 'wellsfargo'],
            ],
            'books_exports' => [
                'label' => 'Books exports',
                'keywords' => ['quickbooks', 'qbo', 'general ledger', 'trial balance', 'profit and loss', 'p&l', 'balance sheet'],
            ],
        ];
    }

    protected function fileMatchesYear(string $path, int $year): bool
    {
        $matches = [];
        preg_match_all('/\b(20\d{2})\b/', $path, $matches);

        foreach ($matches[1] ?? [] as $match) {
            if ((int) $match === $year) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $files
     * @param  array<int, string>  $keywords
     * @return array<string, mixed>
     */
    protected function currentYearCategorySummary(string $root, array $files, string $label, array $keywords): array
    {
        $matches = array_values(array_filter($files, function (string $path) use ($keywords): bool {
            $normalized = strtolower($path);

            foreach ($keywords as $keyword) {
                if (str_contains($normalized, strtolower($keyword))) {
                    return true;
                }
            }

            return false;
        }));

        sort($matches);

        return [
            'label' => $label,
            'count' => count($matches),
            'reviewed_count' => count($matches),
            'needs_review_count' => 0,
            'documents' => array_map(
                fn (string $path): array => [
                    'path' => $this->relativePath($root, $path),
                    'file_name' => basename($path),
                ],
                $matches,
            ),
        ];
    }

    /**
     * @param  array<int, string>  $missingCoreCategories
     */
    protected function currentYearNextAction(string $coverageStatus, array $missingCoreCategories): string
    {
        if ($coverageStatus === 'current') {
            return 'Current-year source files exist in the Financials archive for payroll, books, bank, payment, and pass-through corroboration.';
        }

        if ($coverageStatus === 'partial') {
            return 'Add the remaining current-year Financials archive files: '.implode(', ', $missingCoreCategories).'.';
        }

        return 'No current-year source files were found in the Financials archive. Add 2025/2026 payroll packets, books exports, bank statements, payment confirmations, and pass-through workpapers.';
    }

    protected function emptyCurrentYearEvidence(?int $year): array
    {
        return [
            'tax_year' => $year,
            'document_count' => 0,
            'reviewed_document_count' => 0,
            'needs_review_count' => 0,
            'coverage_status' => 'missing',
            'next_action' => 'No current-year source files were found in the Financials archive.',
            'missing_core_categories' => [],
            'return_packet_count' => 0,
            'acceptance_document_count' => 0,
            'payment_confirmation_count' => 0,
            'transcript_document_count' => 0,
            'payroll_document_count' => 0,
            'k1_document_count' => 0,
            'basis_document_count' => 0,
            'distribution_document_count' => 0,
            'bank_statement_count' => 0,
            'books_export_count' => 0,
            'categories' => [],
        ];
    }

    /**
     * @param  iterable<int, FinancialDocument>  $documents
     * @return array<int, int>
     */
    protected function collectDocumentYears(iterable $documents, ?int $targetYear = null): array
    {
        $years = [];

        foreach ($documents as $document) {
            $year = $this->documentYear($document);

            if ($year === null) {
                continue;
            }

            if ($targetYear !== null && $year >= $targetYear) {
                continue;
            }

            $years[$year] = $year;
        }

        ksort($years);

        return array_values($years);
    }

    protected function resolveRoot(): ?string
    {
        $configured = config('tax.financial_archive_root');

        if (is_string($configured) && $configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/');
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function filesWithin(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            if (basename($pathname) === '.DS_Store') {
                continue;
            }

            $files[] = $pathname;
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, int>
     */
    protected function collectYears(array $paths, ?int $targetYear = null): array
    {
        $years = [];

        foreach ($paths as $path) {
            $matches = [];
            if (preg_match_all('/\b(20\d{2})\b/', $path, $matches) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $match) {
                $year = (int) $match;
                if ($targetYear !== null && $year >= $targetYear) {
                    continue;
                }

                $years[$year] = $year;
            }
        }

        ksort($years);

        return array_values($years);
    }

    protected function isBusinessReturn(string $path, string $taxReturnRoot): bool
    {
        $relativePath = ltrim(str_replace($taxReturnRoot, '', $path), '/');
        $topLevelFolder = explode('/', $relativePath)[0] ?? '';
        $normalized = strtolower($topLevelFolder);

        foreach (['llc', 'inc', 'corp', 'corporation', 'company', 'co.', 'pllc', 'pc', 'lp', 'ltd'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function isPayrollDocument(string $path): bool
    {
        $normalized = strtolower($path);

        foreach (['w-2', 'w2', 'w-3', 'w3', '941', '940', 'payroll', 'gusto', 'withholding', 'oq', 'or-wr'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function relativePath(string $root, string $path): string
    {
        $relativePath = str_replace($root, '', $path);

        return '.'.str_replace('\\', '/', $relativePath);
    }

    protected function shouldIgnoreDocument(FinancialDocument $document): bool
    {
        return $document->document_type === 'tax_return'
            && data_get($document->extracted_data, 'draft') === true;
    }

    protected function isBusinessReturnDocument(FinancialDocument $document): bool
    {
        $searchable = strtolower(implode(' ', array_filter([
            $document->file_name,
            $document->file_path,
            data_get($document->extracted_data, 'summary'),
            data_get($document->extracted_data, 'archive_relative_path'),
        ])));

        foreach (['llc', 'inc', 'corp', 'corporation', 'company', 'pllc', 'pc', 'lp', 'ltd', '1120-s', '1120s', '1120', '1065'] as $marker) {
            if (str_contains($searchable, $marker)) {
                return true;
            }
        }

        return false;
    }

    protected function documentYear(FinancialDocument $document): ?int
    {
        $candidates = [
            data_get($document->extracted_data, 'tax_year'),
            data_get($document->extracted_data, 'extracted_fields.tax_year'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        $matches = [];
        preg_match_all('/\b(20\d{2})\b/', implode(' ', array_filter([
            $document->file_name,
            $document->file_path,
            data_get($document->extracted_data, 'archive_relative_path'),
        ])), $matches);

        foreach ($matches[1] ?? [] as $match) {
            return (int) $match;
        }

        return null;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     * @return array<int, int>
     */
    protected function mergeYears(array $left, array $right): array
    {
        $merged = array_fill_keys(array_merge($left, $right), true);
        $years = array_map('intval', array_keys($merged));
        sort($years);

        return $years;
    }

    /**
     * @param  array<int, int>  $priorBusinessReturnYears
     * @param  array<int, int>  $priorPersonalReturnYears
     * @param  array<int, int>  $bankStatementYears
     * @param  array<int, string>  $payrollDocumentPaths
     * @return array<int, string>
     */
    protected function archiveGaps(
        array $priorBusinessReturnYears,
        array $priorPersonalReturnYears,
        array $bankStatementYears,
        array $payrollDocumentPaths,
    ): array {
        $archiveGaps = [];

        if ($priorBusinessReturnYears === []) {
            $archiveGaps[] = 'prior business returns';
        }
        if ($priorPersonalReturnYears === []) {
            $archiveGaps[] = 'prior personal returns';
        }
        if ($bankStatementYears === []) {
            $archiveGaps[] = 'bank statements';
        }
        if ($payrollDocumentPaths === []) {
            $archiveGaps[] = 'payroll filings and W-2 packets';
        }

        return $archiveGaps;
    }

    /**
     * @param  array<int, string>  $archiveGaps
     * @param  array<int, string>  $payrollDocumentPaths
     */
    protected function coverageStatus(array $archiveGaps, array $payrollDocumentPaths): string
    {
        if ($archiveGaps === []) {
            return 'current';
        }

        if (count($archiveGaps) === 1 && $payrollDocumentPaths === []) {
            return 'partial';
        }

        return count($archiveGaps) < 4 ? 'partial' : 'missing';
    }

    /**
     * @param  array<int, string>  $archiveGaps
     * @param  array<int, string>  $payrollDocumentPaths
     */
    protected function nextAction(
        array $archiveGaps,
        array $payrollDocumentPaths,
        bool $hasUploadedArchive,
        bool $hasFilesystemArchive,
    ): string {
        if ($archiveGaps === []) {
            return 'Historical tax returns, bank statements, and payroll packets are inventoried and available as supporting evidence.';
        }

        if ($payrollDocumentPaths === []) {
            return 'Prior returns and bank statements are available, but no payroll filing history was found in the archive. Add historical W-2/941/940 packets or finish the live payroll filing workflow before treating payroll evidence as complete.';
        }

        if (! $hasUploadedArchive && ! $hasFilesystemArchive) {
            return 'Upload prior returns, bank statements, and payroll support into Documents so Tax Office can corroborate carryovers, payments, and owner compensation history.';
        }

        return 'Fill the remaining archive gaps so prior returns, bank statements, and payroll support can corroborate carryovers, payments, and owner compensation history.';
    }
}
