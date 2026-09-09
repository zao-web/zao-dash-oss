<?php

use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use App\Services\Seo\SeoQualityGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SeoQualityGateService;
});

test('validate includes featured_image check', function () {
    $page = SeoPage::factory()->create([
        'featured_image_wordpress_id' => 123,
    ]);

    $result = $this->service->validate($page);

    expect($result['checks'])->toHaveKey('featured_image')
        ->and($result['checks']['featured_image']['passed'])->toBeTrue()
        ->and($result['checks']['featured_image']['message'])->toBe('Featured image is set');
});

test('validate fails when featured_image is missing', function () {
    $page = SeoPage::factory()->create([
        'featured_image_wordpress_id' => null,
    ]);

    $result = $this->service->validate($page);

    expect($result['checks']['featured_image']['passed'])->toBeFalse()
        ->and($result['checks']['featured_image']['message'])->toBe('Featured image is missing');
});

test('validate includes cta_configured check', function () {
    $page = SeoPage::factory()->create([
        'cta_type' => 'location',
    ]);

    $result = $this->service->validate($page);

    expect($result['checks'])->toHaveKey('cta_configured')
        ->and($result['checks']['cta_configured']['passed'])->toBeTrue()
        ->and($result['checks']['cta_configured']['message'])->toBe('CTA is configured');
});

test('validate fails when cta is not configured', function () {
    $page = SeoPage::factory()->create([
        'cta_type' => null,
    ]);

    $result = $this->service->validate($page);

    expect($result['checks']['cta_configured']['passed'])->toBeFalse()
        ->and($result['checks']['cta_configured']['message'])->toBe('CTA is not configured');
});

test('canPublish returns false when featured_image is missing', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'This is a long enough meta title for testing',
        'meta_description' => str_repeat('a', 150),
        'featured_image_wordpress_id' => null,
        'cta_type' => 'location',
    ]);

    expect($this->service->canPublish($page))->toBeFalse();
});

test('canPublish returns false when cta is not configured', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'This is a long enough meta title for testing',
        'meta_description' => str_repeat('a', 150),
        'featured_image_wordpress_id' => 123,
        'cta_type' => null,
    ]);

    expect($this->service->canPublish($page))->toBeFalse();
});

test('canPublish returns true when all checks pass including featured_image and cta', function () {
    $page = SeoPage::factory()->create([
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'This is a long enough meta title for testing',
        'meta_description' => str_repeat('a', 150),
        'featured_image_wordpress_id' => 123,
        'cta_type' => 'location',
    ]);

    expect($this->service->canPublish($page))->toBeTrue();
});

test('getPublishablePages only returns pages with featured_image and cta', function () {
    // Create a page missing featured image
    SeoPage::factory()->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'featured_image_wordpress_id' => null,
        'cta_type' => 'location',
    ]);

    // Create a page missing CTA
    SeoPage::factory()->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'featured_image_wordpress_id' => 123,
        'cta_type' => null,
    ]);

    // Create a complete page
    $completePage = SeoPage::factory()->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'featured_image_wordpress_id' => 456,
        'cta_type' => 'comparison',
    ]);

    $publishable = $this->service->getPublishablePages();

    expect($publishable)->toHaveCount(1)
        ->and($publishable->first()->id)->toBe($completePage->id);
});

test('getPagesNeedingWork includes pages missing featured_image or cta', function () {
    // Create a page missing featured image
    $missingImage = SeoPage::factory()->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'featured_image_wordpress_id' => null,
        'cta_type' => 'location',
    ]);

    // Create a page missing CTA
    $missingCta = SeoPage::factory()->create([
        'status' => SeoPageStatus::Draft,
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'featured_image_wordpress_id' => 123,
        'cta_type' => null,
    ]);

    $needingWork = $this->service->getPagesNeedingWork();

    expect($needingWork)->toHaveCount(2)
        ->and($needingWork->pluck('id')->toArray())->toContain($missingImage->id)
        ->and($needingWork->pluck('id')->toArray())->toContain($missingCta->id);
});

test('quality score includes featured_image and cta weights', function () {
    // Page with everything except featured_image and cta
    $page = SeoPage::factory()->create([
        'word_count' => 1000,
        'humanization_score' => 95,
        'internal_links_count' => 5,
        'schema_valid' => true,
        'proprietary_data_count' => 3,
        'meta_title' => 'This is a long enough meta title for testing',
        'meta_description' => str_repeat('a', 150),
        'featured_image_wordpress_id' => null,
        'cta_type' => null,
    ]);

    $resultWithoutImageCta = $this->service->validate($page);

    $page->update([
        'featured_image_wordpress_id' => 123,
        'cta_type' => 'location',
    ]);

    $resultWithImageCta = $this->service->validate($page->fresh());

    // Score should be higher with featured_image and cta (10 + 10 = 20 points)
    expect($resultWithImageCta['score'])->toBeGreaterThan($resultWithoutImageCta['score'])
        ->and($resultWithImageCta['score'] - $resultWithoutImageCta['score'])->toBe(20);
});
