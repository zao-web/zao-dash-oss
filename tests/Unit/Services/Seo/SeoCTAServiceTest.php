<?php

use App\Models\SeoPage;
use App\Services\Seo\SeoCTAService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SeoCTAService;
});

test('getCtaForPage returns correct CTA for location playbook', function () {
    $page = SeoPage::factory()->make(['playbook' => 'location']);

    $cta = $this->service->getCtaForPage($page);

    expect($cta['headline'])->toBe('Ready to Build Something Great?')
        ->and($cta['button_text'])->toBe('Schedule a Local Consultation')
        ->and($cta['type'])->toBe('location')
        ->and($cta['url'])->toContain('source=seo')
        ->and($cta['url'])->toContain('type=location');
});

test('getCtaForPage returns correct CTA for comparison playbook', function () {
    $page = SeoPage::factory()->make(['playbook' => 'comparison']);

    $cta = $this->service->getCtaForPage($page);

    expect($cta['headline'])->toBe('Not Sure Which Technology is Right?')
        ->and($cta['button_text'])->toBe('Get Expert Technology Advice')
        ->and($cta['type'])->toBe('comparison');
});

test('getCtaForPage returns correct CTA for case-study playbook', function () {
    $page = SeoPage::factory()->make(['playbook' => 'case-study']);

    $cta = $this->service->getCtaForPage($page);

    expect($cta['headline'])->toBe('Want Similar Results?')
        ->and($cta['button_text'])->toBe('Discuss Your Project')
        ->and($cta['type'])->toBe('case-study');
});

test('getCtaForPage returns default CTA for unknown playbook', function () {
    $page = SeoPage::factory()->make(['playbook' => 'unknown-playbook']);

    $cta = $this->service->getCtaForPage($page);

    expect($cta['headline'])->toBe('Ready to Get Started?')
        ->and($cta['button_text'])->toBe('Contact Us Today')
        ->and($cta['type'])->toBe('general');
});

test('getCtaForPage includes page slug in URL when available', function () {
    $page = SeoPage::factory()->make([
        'playbook' => 'location',
        'url_slug' => 'portland-wordpress-agency',
    ]);

    $cta = $this->service->getCtaForPage($page);

    expect($cta['url'])->toContain('page=portland-wordpress-agency');
});

test('generateCtaHtml returns valid WordPress blocks HTML', function () {
    $cta = [
        'headline' => 'Test Headline',
        'button_text' => 'Click Me',
        'url' => 'https://example.com',
        'description' => 'Test description',
    ];

    $html = $this->service->generateCtaHtml($cta);

    expect($html)->toContain('<!-- wp:group')
        ->and($html)->toContain('seo-cta-section')
        ->and($html)->toContain('Test Headline')
        ->and($html)->toContain('Click Me')
        ->and($html)->toContain('https://example.com')
        ->and($html)->toContain('Test description');
});

test('generateInlineCtaHtml returns button block HTML', function () {
    $cta = [
        'button_text' => 'Learn More',
        'url' => 'https://example.com/contact',
    ];

    $html = $this->service->generateInlineCtaHtml($cta);

    expect($html)->toContain('<!-- wp:buttons')
        ->and($html)->toContain('Learn More')
        ->and($html)->toContain('https://example.com/contact');
});

test('applyToPage updates page with CTA data', function () {
    $page = SeoPage::factory()->create(['playbook' => 'integration']);

    $cta = $this->service->applyToPage($page);

    $page->refresh();

    expect($page->cta_type)->toBe('integration')
        ->and($page->cta_text)->toBe('Get Integration Support')
        ->and($page->cta_url)->toContain('type=integration');
});

test('getAvailableTemplates returns all templates', function () {
    $templates = $this->service->getAvailableTemplates();

    expect($templates)->toBeArray()
        ->and($templates)->toHaveKey('location')
        ->and($templates)->toHaveKey('comparison')
        ->and($templates)->toHaveKey('case-study')
        ->and($templates)->toHaveKey('persona')
        ->and($templates)->toHaveKey('template')
        ->and($templates)->toHaveKey('integration')
        ->and($templates)->toHaveKey('calculator')
        ->and($templates)->toHaveKey('glossary')
        ->and($templates)->toHaveKey('curation');
});

test('getTemplateForPlaybook returns correct template', function () {
    $template = $this->service->getTemplateForPlaybook('calculator');

    expect($template['headline'])->toBe('Ready to Get Started?')
        ->and($template['button_text'])->toBe('Get a Project Estimate')
        ->and($template['type'])->toBe('calculator');
});

test('persona and vertical playbooks return same CTA', function () {
    $personaPage = SeoPage::factory()->make(['playbook' => 'persona']);
    $verticalPage = SeoPage::factory()->make(['playbook' => 'vertical']);

    $personaCta = $this->service->getCtaForPage($personaPage);
    $verticalCta = $this->service->getCtaForPage($verticalPage);

    expect($personaCta['headline'])->toBe($verticalCta['headline'])
        ->and($personaCta['button_text'])->toBe($verticalCta['button_text'])
        ->and($personaCta['type'])->toBe($verticalCta['type']);
});
