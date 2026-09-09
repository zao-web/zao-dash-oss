<?php

namespace App\Services\Rfp;

/**
 * Pre-filters parsed RFP teaser data before it enters the evaluation pipeline.
 *
 * Catches three classes of noise the evaluator shouldn't burn LLM cycles on:
 *   - Federal/SAM.gov procurements (Zao's segment is DMOs + municipalities)
 *   - Non-US opportunities (we don't bid international)
 *   - Low-confidence teaser extractions (likely newsletters, not real RFPs)
 */
class RfpPreFilter
{
    /**
     * Minimum extraction confidence (0.0 – 1.0) from the teaser parser to
     * accept an opportunity automatically. Below this, opportunity is routed
     * to 'declined' with reason 'low_confidence' for human triage.
     */
    public const CONFIDENCE_THRESHOLD = 0.6;

    /**
     * @param  array{title?: string, organization?: string, description?: string, source_type?: string, extraction_confidence?: float, url?: ?string}  $data
     * @return array{passed: bool, reason: ?string, detail: ?string}
     */
    public function evaluate(array $data): array
    {
        $org = strtolower(trim($data['organization'] ?? ''));
        $title = strtolower(trim($data['title'] ?? ''));
        $url = strtolower((string) ($data['url'] ?? ''));
        $haystack = trim("{$org} {$title} {$url}");

        if ($this->isFederal($org, $url)) {
            return $this->reject('federal_blocked', "Federal/SAM.gov opportunity filtered (org: {$data['organization']})");
        }

        if ($this->isNonUS($haystack)) {
            return $this->reject('non_us', "International opportunity filtered (org: {$data['organization']})");
        }

        $confidence = (float) ($data['extraction_confidence'] ?? 1.0);
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return $this->reject('low_confidence', "Teaser parser confidence ({$confidence}) below threshold (".self::CONFIDENCE_THRESHOLD.')');
        }

        return ['passed' => true, 'reason' => null, 'detail' => null];
    }

    /**
     * Federal-government indicators.
     *
     * Hard-block list reflects Justin's stated targeting (DMOs + municipalities,
     * not federal). Edit these patterns if the targeting changes.
     */
    private function isFederal(string $org, string $url): bool
    {
        if (str_contains($url, 'sam.gov')) {
            return true;
        }

        $federalPatterns = [
            'department of',
            'federal ',
            'u.s. ',
            'us department',
            'united states',
            'general services administration',
            'us army', 'u.s. army',
            'us navy', 'u.s. navy', 'naval ',
            'us air force', 'u.s. air force', 'air force',
            'marine corps',
            'national institute',
            'national park service',
            'national oceanic',
            'centers for disease',
            'environmental protection agency',
            'social security administration',
        ];

        foreach ($federalPatterns as $pattern) {
            if (str_contains($org, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Non-US indicators.
     *
     * TODO(justin): Refine this list. Patterns flagged as "international" trigger
     * an auto-decline. False positives cost us US opportunities with these words
     * in their name. False negatives waste LLM cycles. The list below is a
     * conservative starting point — country names that almost always mean
     * non-US procurement.
     */
    private function isNonUS(string $haystack): bool
    {
        $internationalMarkers = [
            'european union',
            ' uk ', 'united kingdom', 'her majesty',
            'canada', 'canadian',
            'australia', 'australian',
            'singapore',
            'germany', 'german federal',
            'france ', 'french government',
            'india government', 'government of india',
        ];

        foreach ($internationalMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{passed: false, reason: string, detail: string}
     */
    private function reject(string $reason, string $detail): array
    {
        return ['passed' => false, 'reason' => $reason, 'detail' => $detail];
    }
}
