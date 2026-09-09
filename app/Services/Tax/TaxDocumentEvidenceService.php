<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaxDocumentEvidenceService
{
    /**
     * @return array{
     *     tax_year: int,
     *     document_count: int,
     *     reviewed_document_count: int,
     *     needs_review_count: int,
     *     coverage_status: string,
     *     next_action: string,
     *     missing_core_categories: array<int, string>,
     *     return_packet_count: int,
     *     acceptance_document_count: int,
     *     payment_confirmation_count: int,
     *     transcript_document_count: int,
     *     payroll_document_count: int,
     *     k1_document_count: int,
     *     basis_document_count: int,
     *     distribution_document_count: int,
     *     entity_closure_record_count: int,
     *     bank_statement_count: int,
     *     books_export_count: int,
     *     categories: array<string, array<string, mixed>>,
     * }
     */
    public function summarize(int $userId, int $year): array
    {
        $documents = FinancialDocument::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->get()
            ->filter(fn (FinancialDocument $document): bool => $this->matchesTaxYear($document, $year))
            ->values();

        $categories = collect($this->categoryDefinitions())
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => $this->categorySummary(
                    label: $definition['label'],
                    documents: $documents,
                    documentTypes: $definition['document_types'],
                    keywords: $definition['keywords'],
                ),
            ])
            ->all();

        $relevantDocuments = collect($categories)
            ->flatMap(fn (array $category): array => $category['documents'])
            ->unique('id')
            ->values();

        $reviewedDocumentCount = $relevantDocuments
            ->filter(fn (array $document): bool => $document['needs_review'] === false)
            ->count();
        $needsReviewCount = $relevantDocuments
            ->filter(fn (array $document): bool => $document['needs_review'] === true)
            ->count();

        $missingCoreCategories = $this->missingCoreCategories($categories);
        $coverageStatus = $this->coverageStatus($categories, $relevantDocuments->count());

        return [
            'tax_year' => $year,
            'document_count' => $relevantDocuments->count(),
            'reviewed_document_count' => $reviewedDocumentCount,
            'needs_review_count' => $needsReviewCount,
            'coverage_status' => $coverageStatus,
            'next_action' => $this->nextAction($coverageStatus, $needsReviewCount, $missingCoreCategories),
            'missing_core_categories' => $missingCoreCategories,
            'return_packet_count' => $categories['return_packets']['count'],
            'acceptance_document_count' => $categories['acceptance_records']['count'],
            'payment_confirmation_count' => $categories['payment_confirmations']['count'],
            'transcript_document_count' => $categories['transcripts']['count'],
            'payroll_document_count' => $categories['payroll_packets']['count'],
            'k1_document_count' => $categories['k1_packages']['count'],
            'basis_document_count' => $categories['basis_workpapers']['count'],
            'distribution_document_count' => $categories['distribution_ledgers']['count'],
            'entity_closure_record_count' => $categories['entity_closure_records']['count'],
            'bank_statement_count' => $categories['bank_statements']['count'],
            'books_export_count' => $categories['books_exports']['count'],
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, array{label: string, document_types: array<int, string>, keywords: array<int, string>}>
     */
    protected function categoryDefinitions(): array
    {
        return [
            'return_packets' => [
                'label' => 'Return packets',
                'document_types' => ['tax_return'],
                'keywords' => ['tax return', 'return packet', '1040', '1120-s', '1120s', '1065', 'or-40'],
            ],
            'acceptance_records' => [
                'label' => 'Acceptance records',
                'document_types' => ['efile_acceptance', 'filing_acceptance', 'extension_acceptance'],
                'keywords' => ['accepted return', 'acknowledgement', 'acknowledgment', 'e-file acceptance', 'efile acceptance'],
            ],
            'payment_confirmations' => [
                'label' => 'Payment confirmations',
                'document_types' => ['payment_confirmation'],
                'keywords' => ['payment confirmation', 'eftps', 'direct pay', 'estimated payment', 'tax payment'],
            ],
            'transcripts' => [
                'label' => 'Transcripts',
                'document_types' => ['tax_transcript', 'account_transcript'],
                'keywords' => ['account transcript', 'return transcript', 'tax transcript', 'wage and income transcript'],
            ],
            'payroll_packets' => [
                'label' => 'Payroll and W-2 packets',
                'document_types' => ['payroll_record', 'w2_packet'],
                'keywords' => ['w-2', 'w2', 'payroll', '941', '940', '1125-e', 'officer compensation'],
            ],
            'k1_packages' => [
                'label' => 'K-1 packages',
                'document_types' => ['k1_package'],
                'keywords' => ['k-1', 'k1', 'schedule e'],
            ],
            'basis_workpapers' => [
                'label' => 'Basis and QBI workpapers',
                'document_types' => ['basis_workpaper'],
                'keywords' => ['7203', 'basis', 'qbi', '8995', '8995-a', 'loss limitation'],
            ],
            'distribution_ledgers' => [
                'label' => 'Distribution ledgers',
                'document_types' => ['distribution_ledger'],
                'keywords' => ['distribution', 'shareholder basis', 'owner transfer'],
            ],
            'entity_closure_records' => [
                'label' => 'Entity closure records',
                'document_types' => ['entity_closure_record'],
                'keywords' => ['dissolution', 'closure record', 'termination', 'articles of dissolution', 'final return'],
            ],
            'bank_statements' => [
                'label' => 'Bank statements',
                'document_types' => ['bank_statement'],
                'keywords' => ['bank statement', 'statement', 'checking', 'savings'],
            ],
            'books_exports' => [
                'label' => 'Books exports',
                'document_types' => ['qbo_export', 'general_ledger', 'trial_balance'],
                'keywords' => ['quickbooks', 'qbo', 'general ledger', 'trial balance', 'profit and loss', 'p&l', 'balance sheet'],
            ],
        ];
    }

    /**
     * @param  Collection<int, FinancialDocument>  $documents
     * @param  array<int, string>  $documentTypes
     * @param  array<int, string>  $keywords
     * @return array<string, mixed>
     */
    protected function categorySummary(string $label, Collection $documents, array $documentTypes, array $keywords): array
    {
        $matches = $documents
            ->filter(fn (FinancialDocument $document): bool => $this->matchesCategory($document, $documentTypes, $keywords))
            ->values();

        return [
            'label' => $label,
            'count' => $matches->count(),
            'reviewed_count' => $matches->filter(fn (FinancialDocument $document): bool => ! $document->needs_review)->count(),
            'needs_review_count' => $matches->filter(fn (FinancialDocument $document): bool => (bool) $document->needs_review)->count(),
            'documents' => $matches
                ->map(fn (FinancialDocument $document): array => [
                    'id' => $document->id,
                    'file_name' => $document->file_name,
                    'document_type' => $document->document_type,
                    'needs_review' => (bool) $document->needs_review,
                    'tax_year' => $this->documentTaxYear($document),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<int, string>  $documentTypes
     * @param  array<int, string>  $keywords
     */
    protected function matchesCategory(FinancialDocument $document, array $documentTypes, array $keywords): bool
    {
        if ($this->shouldIgnoreDocument($document)) {
            return false;
        }

        $documentType = Str::lower((string) $document->document_type);

        if (in_array($documentType, $documentTypes, true)) {
            return true;
        }

        return Str::contains($this->documentSearchText($document), $keywords);
    }

    protected function shouldIgnoreDocument(FinancialDocument $document): bool
    {
        return $document->document_type === 'tax_return'
            && data_get($document->extracted_data, 'draft') === true;
    }

    protected function documentSearchText(FinancialDocument $document): string
    {
        $searchable = [
            $document->document_type,
            $document->file_name,
            $document->file_path,
            data_get($document->extracted_data, 'summary'),
            json_encode(data_get($document->extracted_data, 'extracted_fields')),
        ];

        return Str::lower(collect($searchable)->filter()->implode(' '));
    }

    protected function matchesTaxYear(FinancialDocument $document, int $year): bool
    {
        $documentYear = $this->documentTaxYear($document);

        if ($documentYear !== null) {
            return $documentYear === $year;
        }

        return in_array($year, $this->yearsFromStrings([
            $document->file_name,
            $document->file_path,
            data_get($document->extracted_data, 'summary'),
        ]), true);
    }

    protected function documentTaxYear(FinancialDocument $document): ?int
    {
        $yearCandidates = [
            data_get($document->extracted_data, 'tax_year'),
            data_get($document->extracted_data, 'extracted_fields.tax_year'),
        ];

        foreach ($yearCandidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        $years = $this->yearsFromStrings([$document->file_name, $document->file_path]);

        return $years[0] ?? null;
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, int>
     */
    protected function yearsFromStrings(array $values): array
    {
        $years = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            preg_match_all('/\b(20\d{2})\b/', $value, $matches);

            foreach ($matches[1] ?? [] as $match) {
                $year = (int) $match;
                $years[$year] = $year;
            }
        }

        ksort($years);

        return array_values($years);
    }

    /**
     * @param  array<string, array<string, mixed>>  $categories
     * @return array<int, string>
     */
    protected function missingCoreCategories(array $categories): array
    {
        return collect([
            'books_exports',
            'bank_statements',
            'payment_confirmations',
            'payroll_packets',
            'k1_packages',
            'basis_workpapers',
        ])
            ->filter(fn (string $key): bool => ($categories[$key]['reviewed_count'] ?? 0) === 0)
            ->map(fn (string $key): string => $categories[$key]['label'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $categories
     */
    protected function coverageStatus(array $categories, int $relevantDocumentCount): string
    {
        if ($relevantDocumentCount === 0) {
            return 'missing';
        }

        $reviewedCoreCategoryCount = collect([
            'books_exports',
            'bank_statements',
            'payment_confirmations',
            'payroll_packets',
            'k1_packages',
            'basis_workpapers',
        ])->filter(fn (string $key): bool => ($categories[$key]['reviewed_count'] ?? 0) > 0)->count();

        if ($reviewedCoreCategoryCount >= 5) {
            return 'current';
        }

        return 'partial';
    }

    /**
     * @param  array<int, string>  $missingCoreCategories
     */
    protected function nextAction(string $coverageStatus, int $needsReviewCount, array $missingCoreCategories): string
    {
        if ($needsReviewCount > 0) {
            return 'Review uploaded tax documents before owner signoff so the annual packet cannot step over contradictory evidence.';
        }

        if ($coverageStatus === 'current') {
            return 'Current-year payroll, books, bank, payment, and pass-through documents are reviewed and available to corroborate the draft return packet.';
        }

        if ($coverageStatus === 'partial') {
            return 'Fill the remaining current-year tax-document gaps: '.implode(', ', $missingCoreCategories).'.';
        }

        return 'Upload or classify current-year payroll, books exports, bank statements, payment confirmations, and pass-through workpapers so the return packet is supported by source documents.';
    }
}
