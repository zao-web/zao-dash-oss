<?php

use App\Agents\Tools\SeoGetExistingPagesTool;
use App\Enums\SeoPageStatus;
use App\Models\SeoPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = new SeoGetExistingPagesTool;
});

describe('SeoGetExistingPagesTool', function () {
    test('returns only published pages', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'laravel development',
            'page_url' => 'https://example.com/laravel',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Queued,
            'target_keyword' => 'wordpress development',
            'page_url' => 'https://example.com/wordpress',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Failed,
            'target_keyword' => 'react development',
            'page_url' => 'https://example.com/react',
        ]);

        $result = $this->tool->execute([]);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['keyword'])->toBe('laravel development');
    });

    test('filters by page type', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_type' => 'service_page',
            'target_keyword' => 'laravel services',
            'page_url' => 'https://example.com/laravel',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_type' => 'comparison',
            'target_keyword' => 'laravel vs wordpress',
            'page_url' => 'https://example.com/laravel-vs-wordpress',
        ]);

        $result = $this->tool->execute(['page_type' => 'comparison']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['type'])->toBe('comparison');
    });

    test('filters by playbook', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'playbook' => 'Location',
            'target_keyword' => 'laravel portland',
            'page_url' => 'https://example.com/laravel-portland',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'playbook' => 'Vertical',
            'target_keyword' => 'laravel healthcare',
            'page_url' => 'https://example.com/laravel-healthcare',
        ]);

        $result = $this->tool->execute(['playbook' => 'Location']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['playbook'])->toBe('Location');
    });

    test('filters by technology in keyword', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'laravel development services',
            'page_url' => 'https://example.com/laravel-services',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'wordpress development services',
            'page_url' => 'https://example.com/wordpress-services',
        ]);

        $result = $this->tool->execute(['technology' => 'laravel']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['keyword'])->toContain('laravel');
    });

    test('filters by technology in url', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'web framework services',
            'meta_title' => 'Web Framework Services | Zao',
            'page_url' => 'https://example.com/laravel-development',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'cms development',
            'meta_title' => 'CMS Development | Zao',
            'page_url' => 'https://example.com/wordpress-cms',
        ]);

        $result = $this->tool->execute(['technology' => 'wordpress']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['url'])->toContain('wordpress');
    });

    test('excludes specified page id', function () {
        $page1 = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'laravel services',
            'page_url' => 'https://example.com/laravel',
        ]);

        $page2 = SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'wordpress services',
            'page_url' => 'https://example.com/wordpress',
        ]);

        $result = $this->tool->execute(['exclude_page_id' => $page1->id]);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
        expect($result['data']['pages'][0]['id'])->toBe($page2->id);
    });

    test('searches across multiple fields', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'web development',
            'meta_title' => 'Laravel Web Development',
            'meta_description' => 'Web development services.',
            'page_url' => 'https://example.com/web-dev',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'cms services',
            'meta_title' => 'CMS Services',
            'meta_description' => 'CMS services for business.',
            'page_url' => 'https://example.com/cms',
        ]);

        $result = $this->tool->execute(['search' => 'laravel']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(1);
    });

    test('respects limit parameter', function () {
        SeoPage::factory()->count(10)->create([
            'status' => SeoPageStatus::Published,
        ]);

        $result = $this->tool->execute(['limit' => 5]);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(5);
    });

    test('enforces maximum limit of 50', function () {
        SeoPage::factory()->count(60)->create([
            'status' => SeoPageStatus::Published,
        ]);

        $result = $this->tool->execute(['limit' => 100]);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(50);
    });

    test('sorts by impressions by default', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'impressions_30d' => 100,
            'target_keyword' => 'low traffic page',
            'page_url' => 'https://example.com/low',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'impressions_30d' => 5000,
            'target_keyword' => 'high traffic page',
            'page_url' => 'https://example.com/high',
        ]);

        $result = $this->tool->execute([]);

        expect($result['data']['pages'][0]['keyword'])->toBe('high traffic page');
    });

    test('sorts by clicks when specified', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'clicks_30d' => 10,
            'target_keyword' => 'low clicks page',
            'page_url' => 'https://example.com/low',
        ]);

        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'clicks_30d' => 500,
            'target_keyword' => 'high clicks page',
            'page_url' => 'https://example.com/high',
        ]);

        $result = $this->tool->execute(['sort_by' => 'clicks']);

        expect($result['data']['pages'][0]['keyword'])->toBe('high clicks page');
    });

    test('includes anchor suggestions', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'target_keyword' => 'laravel development portland',
            'meta_title' => 'Laravel Development in Portland | Zao',
            'page_url' => 'https://example.com/laravel-portland',
        ]);

        $result = $this->tool->execute([]);

        expect($result['success'])->toBeTrue();
        expect($result['data']['pages'][0]['anchor_suggestions'])->toBeArray();
        expect($result['data']['pages'][0]['anchor_suggestions'])->toContain('laravel development portland');
    });

    test('includes performance metrics', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'impressions_30d' => 1000,
            'clicks_30d' => 50,
            'page_url' => 'https://example.com/test',
        ]);

        $result = $this->tool->execute([]);

        expect($result['data']['pages'][0]['performance'])->toBeArray();
        expect($result['data']['pages'][0]['performance']['impressions'])->toBe(1000);
        expect($result['data']['pages'][0]['performance']['clicks'])->toBe(50);
    });

    test('returns applied filters in response', function () {
        SeoPage::factory()->create([
            'status' => SeoPageStatus::Published,
            'page_type' => 'service_page',
            'playbook' => 'Vertical',
            'page_url' => 'https://example.com/test',
        ]);

        $result = $this->tool->execute([
            'page_type' => 'service_page',
            'playbook' => 'Vertical',
            'technology' => 'laravel',
        ]);

        expect($result['data']['filters_applied'])->toHaveKey('page_type');
        expect($result['data']['filters_applied']['page_type'])->toBe('service_page');
        expect($result['data']['filters_applied']['playbook'])->toBe('Vertical');
        expect($result['data']['filters_applied']['technology'])->toBe('laravel');
    });

    test('returns empty results gracefully', function () {
        $result = $this->tool->execute(['technology' => 'nonexistent']);

        expect($result['success'])->toBeTrue();
        expect($result['data']['count'])->toBe(0);
        expect($result['data']['pages'])->toBeEmpty();
    });
});
