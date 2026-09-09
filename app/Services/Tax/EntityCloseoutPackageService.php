<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use App\Services\Pdf\TailwindPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class EntityCloseoutPackageService
{
    /**
     * @param  array<string, mixed>  $historicalReturnContext
     * @return array{
     *     is_applicable: bool,
     *     has_current_package: bool,
     *     can_generate_package: bool,
     *     package_document_id: int|null,
     *     package_path: string,
     *     package_status: string,
     *     package_status_label: string,
     *     entity_names: array<int, string>,
     *     dissolution_entities: array<int, string>,
     *     missing_document_requests: array<int, string>,
     *     closure_documents: array<int, array{id: int, file_name: string}>,
     *     tasks: array<int, string>,
     *     next_action: string,
     * }
     */
    public function summarize(int $userId, int $filingYear, array $historicalReturnContext): array
    {
        $entityNames = collect($historicalReturnContext['final_return_entities'] ?? [])
            ->filter(fn (mixed $entity): bool => is_string($entity) && $entity !== '')
            ->values()
            ->all();
        $dissolutionEntities = collect($historicalReturnContext['dissolution_entities'] ?? [])
            ->filter(fn (mixed $entity): bool => is_string($entity) && $entity !== '')
            ->values()
            ->all();
        $missingDocumentRequests = collect($historicalReturnContext['entity_lifecycle_document_requests'] ?? [])
            ->filter(fn (mixed $request): bool => is_string($request) && $request !== '')
            ->values()
            ->all();
        $closureDocuments = FinancialDocument::query()
            ->where('user_id', $userId)
            ->where('document_type', 'entity_closure_record')
            ->orderByDesc('id')
            ->get(['id', 'file_name', 'effective_date', 'extracted_data'])
            ->filter(fn (FinancialDocument $document): bool => $this->matchesFilingYear($document, $filingYear))
            ->map(fn (FinancialDocument $document): array => [
                'id' => $document->id,
                'file_name' => $document->file_name,
            ])
            ->values()
            ->all();
        $artifact = $this->resolveArtifact($userId, $filingYear);

        $canGeneratePackage = $entityNames !== [] && $missingDocumentRequests === [];
        $nextAction = match (true) {
            $entityNames === [] => 'No extra entity closeout package is needed for this filing year.',
            $missingDocumentRequests !== [] => implode(' ', $missingDocumentRequests),
            ! $artifact['exists'] => 'Generate the final-return closeout package once the closure support is on file.',
            default => 'The closeout package is current. Use it alongside the annual return packet for the final-return entity.',
        };

        return [
            'is_applicable' => $entityNames !== [],
            'has_current_package' => $artifact['exists'],
            'can_generate_package' => $canGeneratePackage,
            'package_document_id' => $artifact['document']?->id,
            'package_path' => $artifact['path'],
            'package_status' => $artifact['exists'] ? 'current' : 'not_generated',
            'package_status_label' => $artifact['exists'] ? 'Current' : 'Not generated',
            'entity_names' => $entityNames,
            'dissolution_entities' => $dissolutionEntities,
            'missing_document_requests' => $missingDocumentRequests,
            'closure_documents' => $closureDocuments,
            'tasks' => $this->tasks($entityNames, $dissolutionEntities),
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    public function generate(int $userId, int $filingYear, array $historicalReturnContext, string $format = 'html'): string
    {
        if (! in_array($format, ['html', 'pdf'], true)) {
            throw new InvalidArgumentException('Entity closeout packets must be generated as html or pdf.');
        }

        $summary = $this->summarize($userId, $filingYear, $historicalReturnContext);

        if (! $summary['can_generate_package']) {
            throw new RuntimeException($summary['next_action']);
        }

        $storagePath = $this->artifactPath($userId, $filingYear, $format);
        $mimeType = $format === 'pdf' ? 'application/pdf' : 'text/html';
        $viewData = [
            'filingYear' => $filingYear,
            'summary' => $summary,
        ];

        if ($format === 'pdf') {
            TailwindPdf::view('pdf.tax-forms.entity-closeout-packet', $viewData)
                ->margin(10)
                ->save($storagePath);
        } else {
            Storage::put($storagePath, view('pdf.tax-forms.entity-closeout-packet', $viewData)->render());
        }

        FinancialDocument::updateOrCreate(
            [
                'user_id' => $userId,
                'file_path' => $storagePath,
            ],
            [
                'document_type' => 'entity_closeout_package',
                'file_name' => "entity-closeout-package-{$filingYear}.{$format}",
                'file_size' => Storage::size($storagePath),
                'mime_type' => $mimeType,
                'processing_status' => 'completed',
                'processing_notes' => 'Generated final-return and dissolution closeout packet.',
                'extracted_data' => [
                    'tax_year' => $filingYear,
                    'entity_names' => $summary['entity_names'],
                    'dissolution_entities' => $summary['dissolution_entities'],
                    'closure_document_ids' => array_map(fn (array $document): int => (int) $document['id'], $summary['closure_documents']),
                    'draft' => true,
                ],
                'extraction_confidence' => 1.0,
                'needs_review' => true,
                'reviewed_at' => null,
                'effective_date' => now()->toDateString(),
            ],
        );

        return $storagePath;
    }

    public function artifactPath(int $userId, int $filingYear, string $format = 'html'): string
    {
        $extension = $format === 'pdf' ? 'pdf' : 'html';

        return "tax-forms/{$userId}/{$filingYear}/entity-closeout-package.{$extension}";
    }

    /**
     * @return array{exists: bool, path: string, document: FinancialDocument|null}
     */
    protected function resolveArtifact(int $userId, int $filingYear): array
    {
        foreach ([$this->artifactPath($userId, $filingYear, 'pdf'), $this->artifactPath($userId, $filingYear, 'html')] as $path) {
            if (! Storage::exists($path)) {
                continue;
            }

            return [
                'exists' => true,
                'path' => $path,
                'document' => FinancialDocument::query()
                    ->where('user_id', $userId)
                    ->where('file_path', $path)
                    ->first(),
            ];
        }

        return [
            'exists' => false,
            'path' => $this->artifactPath($userId, $filingYear, 'html'),
            'document' => null,
        ];
    }

    /**
     * @param  array<int, string>  $entityNames
     * @param  array<int, string>  $dissolutionEntities
     * @return array<int, string>
     */
    protected function tasks(array $entityNames, array $dissolutionEntities): array
    {
        if ($entityNames === []) {
            return [];
        }

        $tasks = [
            'Mark the federal business return as final and verify the shareholder K-1 package reflects the shutdown year.',
            'Reconcile distributions, basis, and any final owner payroll before treating the entity as closed.',
            'Keep the dissolution / closure record with the filing packet and final books workpapers.',
        ];

        if ($dissolutionEntities !== []) {
            $tasks[] = 'Confirm Oregon and Secretary of State closure steps are complete for the dissolving entity.';
        }

        $tasks[] = 'If payroll ran in the closeout year, confirm the final W-2, W-3, 941, 940, and Oregon payroll filings are included.';

        return $tasks;
    }

    protected function matchesFilingYear(FinancialDocument $document, int $filingYear): bool
    {
        $taxYear = data_get($document->extracted_data, 'tax_year')
            ?? data_get($document->extracted_data, 'extracted_fields.tax_year');

        if (is_numeric($taxYear) && (int) $taxYear === $filingYear) {
            return true;
        }

        if ($document->effective_date?->year === $filingYear) {
            return true;
        }

        return Str::contains($document->file_name, (string) $filingYear);
    }
}
