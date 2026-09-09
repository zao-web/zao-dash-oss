<?php

namespace App\Services\Seo;

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;

class SeoQualityGateService
{
    /**
     * Minimum requirements for publishing.
     */
    private const MIN_WORD_COUNT = 800;

    private const MIN_HUMANIZATION_SCORE = 90;

    private const MIN_INTERNAL_LINKS = 3;

    private const MIN_PROPRIETARY_DATA_COUNT = 2;

    /**
     * Check if a page passes all quality gates for publishing.
     */
    public function canPublish(SeoPage $page): bool
    {
        $validation = $this->validate($page);

        return $validation['passed'];
    }

    /**
     * Validate a page against all quality gates.
     */
    public function validate(SeoPage $page): array
    {
        $checks = [
            'word_count' => $this->checkWordCount($page),
            'humanization_score' => $this->checkHumanizationScore($page),
            'internal_links' => $this->checkInternalLinks($page),
            'schema_valid' => $this->checkSchemaValid($page),
            'proprietary_data' => $this->checkProprietaryData($page),
            'meta_complete' => $this->checkMetaComplete($page),
            'featured_image' => $this->checkFeaturedImage($page),
            'cta_configured' => $this->checkCtaConfigured($page),
        ];

        $passed = collect($checks)->every(fn ($check) => $check['passed']);

        return [
            'passed' => $passed,
            'checks' => $checks,
            'score' => $this->calculateQualityScore($checks),
        ];
    }

    /**
     * Get pages that are ready for publishing.
     */
    public function getPublishablePages(): \Illuminate\Database\Eloquent\Collection
    {
        return SeoPage::where('status', SeoPageStatus::Draft)
            ->where('word_count', '>=', self::MIN_WORD_COUNT)
            ->where('humanization_score', '>=', self::MIN_HUMANIZATION_SCORE)
            ->where('internal_links_count', '>=', self::MIN_INTERNAL_LINKS)
            ->where('schema_valid', true)
            ->where('proprietary_data_count', '>=', self::MIN_PROPRIETARY_DATA_COUNT)
            ->whereNotNull('featured_image_wordpress_id')
            ->whereNotNull('cta_type')
            ->get();
    }

    /**
     * Get pages that need improvement before publishing.
     */
    public function getPagesNeedingWork(): \Illuminate\Database\Eloquent\Collection
    {
        return SeoPage::where('status', SeoPageStatus::Draft)
            ->where(function ($query) {
                $query->where('word_count', '<', self::MIN_WORD_COUNT)
                    ->orWhere('humanization_score', '<', self::MIN_HUMANIZATION_SCORE)
                    ->orWhere('internal_links_count', '<', self::MIN_INTERNAL_LINKS)
                    ->orWhere('schema_valid', false)
                    ->orWhere('proprietary_data_count', '<', self::MIN_PROPRIETARY_DATA_COUNT)
                    ->orWhereNull('featured_image_wordpress_id')
                    ->orWhereNull('cta_type');
            })
            ->get();
    }

    /**
     * Auto-publish pages that pass all quality gates.
     */
    public function autoPublishReady(): int
    {
        $pages = $this->getPublishablePages();
        $published = 0;

        foreach ($pages as $page) {
            $page->update([
                'status' => SeoPageStatus::Published,
                'published_at' => now(),
            ]);
            $published++;
        }

        return $published;
    }

    private function checkWordCount(SeoPage $page): array
    {
        $passed = ($page->word_count ?? 0) >= self::MIN_WORD_COUNT;

        return [
            'passed' => $passed,
            'current' => $page->word_count ?? 0,
            'required' => self::MIN_WORD_COUNT,
            'message' => $passed
                ? 'Word count meets minimum requirement'
                : "Word count ({$page->word_count}) below minimum (".self::MIN_WORD_COUNT.')',
        ];
    }

    private function checkHumanizationScore(SeoPage $page): array
    {
        $passed = ($page->humanization_score ?? 0) >= self::MIN_HUMANIZATION_SCORE;

        return [
            'passed' => $passed,
            'current' => $page->humanization_score ?? 0,
            'required' => self::MIN_HUMANIZATION_SCORE,
            'message' => $passed
                ? 'Humanization score meets requirement'
                : "Humanization score ({$page->humanization_score}) below minimum (".self::MIN_HUMANIZATION_SCORE.')',
        ];
    }

    private function checkInternalLinks(SeoPage $page): array
    {
        $passed = ($page->internal_links_count ?? 0) >= self::MIN_INTERNAL_LINKS;

        return [
            'passed' => $passed,
            'current' => $page->internal_links_count ?? 0,
            'required' => self::MIN_INTERNAL_LINKS,
            'message' => $passed
                ? 'Internal links meet requirement'
                : "Internal links ({$page->internal_links_count}) below minimum (".self::MIN_INTERNAL_LINKS.')',
        ];
    }

    private function checkSchemaValid(SeoPage $page): array
    {
        $passed = (bool) $page->schema_valid;

        return [
            'passed' => $passed,
            'current' => $passed,
            'required' => true,
            'message' => $passed
                ? 'Schema markup is valid'
                : 'Schema markup needs validation',
        ];
    }

    private function checkProprietaryData(SeoPage $page): array
    {
        $passed = ($page->proprietary_data_count ?? 0) >= self::MIN_PROPRIETARY_DATA_COUNT;

        return [
            'passed' => $passed,
            'current' => $page->proprietary_data_count ?? 0,
            'required' => self::MIN_PROPRIETARY_DATA_COUNT,
            'message' => $passed
                ? 'Proprietary data requirements met'
                : "Proprietary data points ({$page->proprietary_data_count}) below minimum (".self::MIN_PROPRIETARY_DATA_COUNT.')',
        ];
    }

    private function checkMetaComplete(SeoPage $page): array
    {
        $hasTitle = ! empty($page->meta_title) && strlen($page->meta_title) >= 30;
        $hasDescription = ! empty($page->meta_description) && strlen($page->meta_description) >= 100;
        $passed = $hasTitle && $hasDescription;

        return [
            'passed' => $passed,
            'current' => [
                'title_length' => strlen($page->meta_title ?? ''),
                'description_length' => strlen($page->meta_description ?? ''),
            ],
            'required' => [
                'title_min' => 30,
                'description_min' => 100,
            ],
            'message' => $passed
                ? 'Meta title and description are complete'
                : 'Meta title or description needs improvement',
        ];
    }

    private function checkFeaturedImage(SeoPage $page): array
    {
        $passed = $page->hasFeaturedImage();

        return [
            'passed' => $passed,
            'current' => $page->featured_image_wordpress_id,
            'required' => 'not null',
            'message' => $passed
                ? 'Featured image is set'
                : 'Featured image is missing',
        ];
    }

    private function checkCtaConfigured(SeoPage $page): array
    {
        $passed = $page->hasCta();

        return [
            'passed' => $passed,
            'current' => $page->cta_type,
            'required' => 'not null',
            'message' => $passed
                ? 'CTA is configured'
                : 'CTA is not configured',
        ];
    }

    private function calculateQualityScore(array $checks): int
    {
        $weights = [
            'word_count' => 15,
            'humanization_score' => 20,
            'internal_links' => 10,
            'schema_valid' => 10,
            'proprietary_data' => 15,
            'meta_complete' => 10,
            'featured_image' => 10,
            'cta_configured' => 10,
        ];

        $score = 0;
        foreach ($checks as $name => $check) {
            if ($check['passed']) {
                $score += $weights[$name] ?? 0;
            }
        }

        return $score;
    }
}
