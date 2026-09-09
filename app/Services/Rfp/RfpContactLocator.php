<?php

namespace App\Services\Rfp;

use App\Models\Document;
use App\Models\Email;
use App\Models\RfpOpportunity;
use Illuminate\Support\Facades\Log;

/**
 * Locates submission contact details for an RFP using local signals.
 *
 * Order of preference, highest signal first:
 *   1. Submission email already on the opportunity (no-op)
 *   2. Most recent Email from the issuing organization's domain
 *   3. Most recent Document referencing the issuing organization
 *
 * Returns a structured result so callers can decide whether to proceed or
 * halt generation. We deliberately do NOT fall back to generic info@ guesses
 * — sending a proposal to the wrong inbox is worse than waiting for a human.
 */
class RfpContactLocator
{
    /**
     * @return array{found: bool, email: ?string, name: ?string, source: ?string, candidates: array<int, array<string, string>>}
     */
    public function locate(RfpOpportunity $opportunity): array
    {
        if ($opportunity->submission_email) {
            return $this->result(true, $opportunity->submission_email, $opportunity->contact_name, 'opportunity_field');
        }

        // contact_email is a strong signal — usually parsed from the RFP doc
        // by RetrieveRfpDocumentJob. Promote it to submission_email here.
        if ($opportunity->contact_email) {
            return $this->result(true, $opportunity->contact_email, $opportunity->contact_name, 'contact_email_field');
        }

        $domain = $this->extractDomain($opportunity);
        $candidates = [];

        if ($domain) {
            $emailMatch = $this->searchEmails($domain);
            if ($emailMatch) {
                $candidates[] = $emailMatch + ['source' => 'gmail_history'];
            }
        }

        $docMatch = $this->searchDocuments($opportunity);
        if ($docMatch) {
            $candidates[] = $docMatch + ['source' => 'drive_documents'];
        }

        Log::info('RfpContactLocator: located candidates', [
            'opportunity_id' => $opportunity->id,
            'organization' => $opportunity->issuing_organization,
            'candidate_count' => count($candidates),
            'domain' => $domain,
        ]);

        if (empty($candidates)) {
            return $this->result(false, null, null, null, []);
        }

        $best = $candidates[0];

        return $this->result(true, $best['email'], $best['name'] ?? null, $best['source'], $candidates);
    }

    private function searchEmails(string $domain): ?array
    {
        $email = Email::query()
            ->where('from_address', 'like', "%@{$domain}")
            ->orderByDesc('received_at')
            ->first(['from_address', 'from_name']);

        if (! $email) {
            return null;
        }

        return [
            'email' => $email->from_address,
            'name' => $email->from_name,
        ];
    }

    private function searchDocuments(RfpOpportunity $opportunity): ?array
    {
        $org = $opportunity->issuing_organization;

        if (! $org) {
            return null;
        }

        $document = Document::query()
            ->whereNotNull('extracted_client_name')
            ->whereRaw('LOWER(extracted_client_name) LIKE ?', ['%'.strtolower(trim($org)).'%'])
            ->orderByDesc('google_modified_at')
            ->first(['content_excerpt', 'extracted_client_name']);

        if (! $document || ! $document->content_excerpt) {
            return null;
        }

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $document->content_excerpt, $matches)) {
            return [
                'email' => $matches[0],
                'name' => $document->extracted_client_name,
            ];
        }

        return null;
    }

    private function extractDomain(RfpOpportunity $opportunity): ?string
    {
        if ($opportunity->organization_website) {
            $host = parse_url($opportunity->organization_website, PHP_URL_HOST);
            if ($host) {
                return preg_replace('/^www\./', '', strtolower($host));
            }
        }

        if ($opportunity->contact_email && str_contains($opportunity->contact_email, '@')) {
            return strtolower(explode('@', $opportunity->contact_email)[1]);
        }

        return null;
    }

    /**
     * @return array{found: bool, email: ?string, name: ?string, source: ?string, candidates: array}
     */
    private function result(bool $found, ?string $email, ?string $name, ?string $source, array $candidates = []): array
    {
        return [
            'found' => $found,
            'email' => $email,
            'name' => $name,
            'source' => $source,
            'candidates' => $candidates,
        ];
    }
}
