<?php

use App\Enums\SeoPageStatus;
use App\Events\LeadAttributedToSeoPage;
use App\Models\Lead;
use App\Models\SeoPage;
use App\Services\Seo\LeadAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new LeadAttributionService;
});

describe('LeadAttributionService', function () {
    describe('attributeLeadFromForm', function () {
        test('updates lead with attribution data', function () {
            $lead = Lead::factory()->create();

            $attributionData = [
                'first_touch_page_url' => 'https://example.com/laravel-development',
                'first_touch_keyword' => 'laravel development',
                'first_touch_source' => 'google',
                'first_touch_medium' => 'organic',
                'first_touch_campaign' => 'spring-2026',
                'last_touch_page_url' => 'https://example.com/contact',
                'pages_viewed' => 5,
                'time_on_site_seconds' => 180,
                'max_scroll_depth' => 75,
                'ga4_client_id' => 'GA1.1.123456789',
                'ga4_session_id' => 'session-123',
            ];

            $result = $this->service->attributeLeadFromForm($lead, $attributionData);

            expect($result->first_touch_page_url)->toBe('https://example.com/laravel-development');
            expect($result->first_touch_keyword)->toBe('laravel development');
            expect($result->first_touch_source)->toBe('google');
            expect($result->first_touch_medium)->toBe('organic');
            expect($result->first_touch_campaign)->toBe('spring-2026');
            expect($result->last_touch_page_url)->toBe('https://example.com/contact');
            expect($result->pages_viewed)->toBe(5);
            expect($result->time_on_site_seconds)->toBe(180);
            expect($result->max_scroll_depth)->toBe(75);
            expect($result->ga4_client_id)->toBe('GA1.1.123456789');
            expect($result->ga4_session_id)->toBe('session-123');
        });

        test('handles partial attribution data', function () {
            $lead = Lead::factory()->create();

            $attributionData = [
                'first_touch_page_url' => 'https://example.com/wordpress',
                'first_touch_source' => 'referral',
            ];

            $result = $this->service->attributeLeadFromForm($lead, $attributionData);

            expect($result->first_touch_page_url)->toBe('https://example.com/wordpress');
            expect($result->first_touch_source)->toBe('referral');
            expect($result->first_touch_medium)->toBeNull();
        });
    });

    describe('linkToSeoPage', function () {
        test('links lead to matching SEO page by URL', function () {
            Event::fake([LeadAttributedToSeoPage::class]);

            $seoPage = SeoPage::factory()->create([
                'page_url' => 'https://example.com/laravel-development',
                'status' => SeoPageStatus::Published,
                'total_leads' => 0,
            ]);

            $lead = Lead::factory()->create([
                'first_touch_page_url' => 'https://example.com/laravel-development',
            ]);

            $result = $this->service->linkToSeoPage($lead);

            expect($result)->toBeTrue();
            expect($lead->fresh()->seo_page_id)->toBe($seoPage->id);
            expect($seoPage->fresh()->total_leads)->toBe(1);

            Event::assertDispatched(LeadAttributedToSeoPage::class, function ($event) use ($lead, $seoPage) {
                return $event->lead->id === $lead->id && $event->seoPage->id === $seoPage->id;
            });
        });

        test('returns false when no first touch URL', function () {
            $lead = Lead::factory()->create([
                'first_touch_page_url' => null,
            ]);

            $result = $this->service->linkToSeoPage($lead);

            expect($result)->toBeFalse();
        });

        test('returns false when no matching SEO page found', function () {
            $lead = Lead::factory()->create([
                'first_touch_page_url' => 'https://example.com/nonexistent-page',
            ]);

            $result = $this->service->linkToSeoPage($lead);

            expect($result)->toBeFalse();
            expect($lead->fresh()->seo_page_id)->toBeNull();
        });

        test('matches SEO page by path when full URL differs', function () {
            Event::fake([LeadAttributedToSeoPage::class]);

            $seoPage = SeoPage::factory()->create([
                'page_url' => 'https://example.com/wordpress-development',
                'status' => SeoPageStatus::Published,
                'total_leads' => 5,
            ]);

            $lead = Lead::factory()->create([
                'first_touch_page_url' => 'https://www.example.com/wordpress-development?utm_source=google',
            ]);

            $result = $this->service->linkToSeoPage($lead);

            expect($result)->toBeTrue();
            expect($lead->fresh()->seo_page_id)->toBe($seoPage->id);
            expect($seoPage->fresh()->total_leads)->toBe(6);
        });
    });

    describe('findSeoPageByUrl', function () {
        test('finds page by exact URL match', function () {
            $seoPage = SeoPage::factory()->create([
                'page_url' => 'https://example.com/react-native-development',
            ]);

            $result = $this->service->findSeoPageByUrl('https://example.com/react-native-development');

            expect($result)->not->toBeNull();
            expect($result->id)->toBe($seoPage->id);
        });

        test('normalizes trailing slashes', function () {
            $seoPage = SeoPage::factory()->create([
                'page_url' => 'https://example.com/healthcare-development',
            ]);

            $result = $this->service->findSeoPageByUrl('https://example.com/healthcare-development/');

            expect($result)->not->toBeNull();
            expect($result->id)->toBe($seoPage->id);
        });

        test('returns null for non-matching URL', function () {
            $result = $this->service->findSeoPageByUrl('https://example.com/does-not-exist');

            expect($result)->toBeNull();
        });
    });

    describe('calculateLeadValue', function () {
        test('returns full deal value for converted leads', function () {
            $lead = Lead::factory()->converted()->create([
                'deal_value' => 50000,
            ]);

            $value = $this->service->calculateLeadValue($lead);

            expect($value)->toBe(50000.0);
        });

        test('returns weighted value for unconverted leads', function () {
            $lead = Lead::factory()->create([
                'deal_value' => 100000,
                'probability' => 25,
            ]);

            $value = $this->service->calculateLeadValue($lead);

            expect($value)->toBe(25000.0);
        });

        test('returns zero for leads without deal value', function () {
            $lead = Lead::factory()->create([
                'deal_value' => null,
                'probability' => 50,
            ]);

            $value = $this->service->calculateLeadValue($lead);

            expect($value)->toBe(0.0);
        });
    });

    describe('getAttributionSummary', function () {
        test('returns comprehensive attribution summary', function () {
            $seoPage = SeoPage::factory()->create([
                'status' => SeoPageStatus::Published,
            ]);

            // Create some leads attributed to this page
            Lead::factory()->count(3)->create([
                'seo_page_id' => $seoPage->id,
                'deal_value' => 10000,
                'first_touch_source' => 'google',
                'time_on_site_seconds' => 120,
                'pages_viewed' => 4,
            ]);

            Lead::factory()->converted()->create([
                'seo_page_id' => $seoPage->id,
                'deal_value' => 25000,
                'first_touch_source' => 'referral',
                'time_on_site_seconds' => 300,
                'pages_viewed' => 8,
            ]);

            $summary = $this->service->getAttributionSummary($seoPage);

            expect($summary['total_leads'])->toBe(4);
            expect((float) $summary['total_value'])->toBe(55000.0);
            expect($summary['converted_leads'])->toBe(1);
            expect((float) $summary['conversion_rate'])->toBe(25.0);
            expect((float) $summary['avg_time_on_site'])->toBe(165.0);
            expect((float) $summary['avg_pages_viewed'])->toBe(5.0);
            expect($summary['top_sources'])->toHaveKey('google');
        });

        test('handles pages with no leads', function () {
            $seoPage = SeoPage::factory()->create([
                'status' => SeoPageStatus::Published,
            ]);

            $summary = $this->service->getAttributionSummary($seoPage);

            expect($summary['total_leads'])->toBe(0);
            expect((float) $summary['total_value'])->toBe(0.0);
            expect($summary['converted_leads'])->toBe(0);
            expect((float) $summary['conversion_rate'])->toBe(0.0);
        });
    });
});
