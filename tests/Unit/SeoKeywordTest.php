<?php

use App\Models\SeoKeyword;
use App\Models\SeoPage;

test('determines if keyword is worth targeting', function () {
    $worthTargeting = SeoKeyword::factory()->create([
        'difficulty_score' => 65,
        'estimated_monthly_volume' => 500,
        'seo_page_id' => null,
    ]);

    $tooHard = SeoKeyword::factory()->create([
        'difficulty_score' => 85,
        'estimated_monthly_volume' => 500,
        'seo_page_id' => null,
    ]);

    $alreadyTargeted = SeoKeyword::factory()->create([
        'difficulty_score' => 50,
        'estimated_monthly_volume' => 500,
        'seo_page_id' => SeoPage::factory()->create()->id,
    ]);

    expect($worthTargeting->isWorthTargeting())->toBeTrue();
    expect($tooHard->isWorthTargeting())->toBeFalse();
    expect($alreadyTargeted->isWorthTargeting())->toBeFalse();
});

test('active scope filters active keywords only', function () {
    SeoKeyword::factory()->create(['status' => 'active']);
    SeoKeyword::factory()->create(['status' => 'active']);
    SeoKeyword::factory()->create(['status' => 'archived']);

    expect(SeoKeyword::active()->count())->toBe(2);
});

test('commercial intent scope filters transactional keywords', function () {
    SeoKeyword::factory()->create(['intent' => 'transactional']);
    SeoKeyword::factory()->create(['intent' => 'transactional']);
    SeoKeyword::factory()->create(['intent' => 'informational']);

    expect(SeoKeyword::commercialIntent()->count())->toBe(2);
});

test('worth targeting scope filters correctly', function () {
    // Worth targeting
    SeoKeyword::factory()->create([
        'difficulty_score' => 50,
        'estimated_monthly_volume' => 300,
        'seo_page_id' => null,
    ]);

    // Too difficult
    SeoKeyword::factory()->create([
        'difficulty_score' => 80,
        'estimated_monthly_volume' => 300,
        'seo_page_id' => null,
    ]);

    // Already has page
    SeoKeyword::factory()->create([
        'difficulty_score' => 50,
        'estimated_monthly_volume' => 300,
        'seo_page_id' => SeoPage::factory()->create()->id,
    ]);

    expect(SeoKeyword::worthTargeting()->count())->toBe(1);
});

test('belongs to seo page', function () {
    $page = SeoPage::factory()->create();
    $keyword = SeoKeyword::factory()->create(['seo_page_id' => $page->id]);

    expect($keyword->seoPage->id)->toBe($page->id);
});
