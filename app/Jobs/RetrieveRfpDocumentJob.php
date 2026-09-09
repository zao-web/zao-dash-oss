<?php

namespace App\Jobs;

use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retrieve, download, and parse a full RFP document for an opportunity.
 *
 * Attempts to find the full document via URL or web search, downloads it,
 * and uses AI to extract structured requirements, evaluation criteria,
 * timeline, budget, and submission details.
 */
class RetrieveRfpDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 60;

    public function __construct(
        public int $rfpOpportunityId,
    ) {
        $this->onQueue('slack-mentions');
    }

    public function handle(RfpDocumentService $documentService): void
    {
        $opportunity = RfpOpportunity::find($this->rfpOpportunityId);

        if (! $opportunity) {
            Log::warning('RetrieveRfpDocumentJob: Opportunity not found', [
                'opportunity_id' => $this->rfpOpportunityId,
            ]);

            return;
        }

        Log::info('RetrieveRfpDocumentJob: Starting document retrieval', [
            'opportunity_id' => $opportunity->id,
            'title' => $opportunity->title,
            'has_document_url' => (bool) $opportunity->full_document_url,
            'has_document_path' => (bool) $opportunity->full_document_path,
        ]);

        // Step 1: If we already have a stored document, skip to parsing
        if ($opportunity->full_document_path) {
            Log::info('RetrieveRfpDocumentJob: Document already stored, proceeding to parse', [
                'opportunity_id' => $opportunity->id,
                'path' => $opportunity->full_document_path,
            ]);

            $this->parseAndUpdate($opportunity, $documentService);

            return;
        }

        // Step 2: If we have a URL, download it
        $documentUrl = $opportunity->full_document_url;

        if (! $documentUrl) {
            // Step 3: Try to search for the document
            Log::info('RetrieveRfpDocumentJob: No document URL, searching web', [
                'opportunity_id' => $opportunity->id,
            ]);

            $documentUrl = $documentService->searchForDocument($opportunity);

            if ($documentUrl) {
                $opportunity->update(['full_document_url' => $documentUrl]);
                Log::info('RetrieveRfpDocumentJob: Found document URL via search', [
                    'opportunity_id' => $opportunity->id,
                    'url' => $documentUrl,
                ]);
            }
        }

        // Step 4: Download the document if we have a URL
        if ($documentUrl) {
            $localPath = $documentService->downloadDocument($documentUrl, $opportunity);

            if ($localPath) {
                $opportunity->update(['full_document_path' => $localPath]);
                Log::info('RetrieveRfpDocumentJob: Document downloaded', [
                    'opportunity_id' => $opportunity->id,
                    'path' => $localPath,
                ]);
            } else {
                Log::warning('RetrieveRfpDocumentJob: Download failed, attempting parse from URL content', [
                    'opportunity_id' => $opportunity->id,
                    'url' => $documentUrl,
                ]);
            }
        } else {
            Log::info('RetrieveRfpDocumentJob: No document URL found, skipping parse', [
                'opportunity_id' => $opportunity->id,
            ]);

            return;
        }

        // Step 5: Parse the document
        $this->parseAndUpdate($opportunity, $documentService);
    }

    /**
     * Parse the document and update the opportunity with extracted data.
     */
    protected function parseAndUpdate(RfpOpportunity $opportunity, RfpDocumentService $documentService): void
    {
        $parsed = $documentService->parseDocument($opportunity);

        if (empty($parsed['requirements_summary']) && empty($parsed['tech_requirements'])) {
            Log::warning('RetrieveRfpDocumentJob: Parse returned no meaningful data', [
                'opportunity_id' => $opportunity->id,
            ]);

            return;
        }

        $updateData = [];

        if (! empty($parsed['requirements_summary'])) {
            $updateData['requirements_summary'] = $parsed['requirements_summary'];
        }

        if (! empty($parsed['tech_requirements'])) {
            $updateData['tech_requirements'] = $parsed['tech_requirements'];
        }

        if (! empty($parsed['evaluation_criteria'])) {
            $updateData['evaluation_criteria'] = $parsed['evaluation_criteria'];
        }

        if (! empty($parsed['timeline_requirements'])) {
            $updateData['timeline_requirements'] = $parsed['timeline_requirements'];
        }

        // Update submission details if not already set
        if ($parsed['submission_method'] && ! $opportunity->submission_method) {
            $updateData['submission_method'] = $parsed['submission_method'];
        }

        if ($parsed['submission_email'] && ! $opportunity->submission_email) {
            $updateData['submission_email'] = $parsed['submission_email'];
        }

        if ($parsed['submission_portal_url'] && ! $opportunity->submission_portal_url) {
            $updateData['submission_portal_url'] = $parsed['submission_portal_url'];
        }

        // Update contact info if not already set
        if ($parsed['contact_name'] && ! $opportunity->contact_name) {
            $updateData['contact_name'] = $parsed['contact_name'];
        }

        if ($parsed['contact_email'] && ! $opportunity->contact_email) {
            $updateData['contact_email'] = $parsed['contact_email'];
        }

        if ($parsed['contact_phone'] && ! $opportunity->contact_phone) {
            $updateData['contact_phone'] = $parsed['contact_phone'];
        }

        // Update budget if parsed and not already set
        if ($parsed['budget_min'] && ! $opportunity->budget_min) {
            $updateData['budget_min'] = $parsed['budget_min'];
        }

        if ($parsed['budget_max'] && ! $opportunity->budget_max) {
            $updateData['budget_max'] = $parsed['budget_max'];
        }

        if (! empty($updateData)) {
            $opportunity->update($updateData);
        }

        Log::info('RetrieveRfpDocumentJob: Document parsed and opportunity updated', [
            'opportunity_id' => $opportunity->id,
            'fields_updated' => array_keys($updateData),
            'requirements_count' => count($parsed['requirements_summary'] ?? []),
            'tech_requirements_count' => count($parsed['tech_requirements'] ?? []),
        ]);
    }
}
