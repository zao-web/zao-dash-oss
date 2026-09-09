<?php

use App\Models\SeoPage;
use App\Services\Seo\SchemaGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SchemaGeneratorService;
});

describe('SchemaGeneratorService', function () {
    test('generates organization schema', function () {
        $schema = $this->service->getOrganizationSchema();

        expect($schema['@type'])->toBe('Organization');
        expect($schema['@id'])->toBe('https://example.com/#organization');
        expect($schema['name'])->toBe('Zao');
        expect($schema['url'])->toBe('https://example.com');
        expect($schema['sameAs'])->toBeArray();
        expect($schema['sameAs'])->toContain('https://github.com/example');
    });

    test('generates service schema for vertical pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Vertical',
            'target_keyword' => 'laravel development healthcare',
            'meta_title' => 'Laravel Development for Healthcare | Zao',
            'meta_description' => 'Expert Laravel development for healthcare companies.',
            'page_url' => 'https://example.com/laravel-healthcare',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('Service');
        expect($schema['name'])->toBe('Laravel Development for Healthcare | Zao');
        expect($schema['serviceType'])->toBe('Laravel Development');
    });

    test('generates comparison schema for comparison pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Comparisons',
            'target_keyword' => 'laravel vs wordpress',
            'meta_title' => 'Laravel vs WordPress: Which is Better? | Zao',
            'meta_description' => 'Comparing Laravel and WordPress for your project.',
            'page_url' => 'https://example.com/laravel-vs-wordpress',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('ItemList');
        expect($schema['itemListElement'])->toBeArray();
        expect($schema['numberOfItems'])->toBe(2);
    });

    test('generates local business schema for location pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Location',
            'target_keyword' => 'laravel agency portland',
            'meta_title' => 'Laravel Agency Portland | Zao',
            'meta_description' => 'Leading Laravel agency in Portland, Oregon.',
            'page_url' => 'https://example.com/laravel-portland',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('ProfessionalService');
        expect($schema['address']['@type'])->toBe('PostalAddress');
        expect($schema['address']['addressLocality'])->toBe('Portland');
        expect($schema['address']['addressRegion'])->toBe('OR');
        expect($schema['geo']['@type'])->toBe('GeoCoordinates');
    });

    test('generates article schema for case study pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Case Study',
            'target_keyword' => 'healthcare app case study',
            'meta_title' => 'Healthcare App Development Case Study | Zao',
            'meta_description' => 'How we built a HIPAA-compliant healthcare app.',
            'page_url' => 'https://example.com/case-study-healthcare',
            'published_at' => now()->subDays(10),
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('Article');
        expect($schema['headline'])->toBe('Healthcare App Development Case Study | Zao');
        expect($schema['author']['@id'])->toBe('https://example.com/#organization');
        expect($schema['datePublished'])->not->toBeNull();
    });

    test('generates faq schema for glossary pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Glossary',
            'target_keyword' => 'what is laravel',
            'meta_title' => 'What is Laravel? A Complete Guide | Zao',
            'meta_description' => 'Learn what Laravel is and why it is the best PHP framework.',
            'page_url' => 'https://example.com/glossary/laravel',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('FAQPage');
        expect($schema['name'])->toBe('What is Laravel? A Complete Guide | Zao');
    });

    test('generates software schema for integration pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Integration',
            'target_keyword' => 'laravel stripe integration',
            'meta_title' => 'Laravel Stripe Integration Guide | Zao',
            'meta_description' => 'How to integrate Stripe with Laravel.',
            'page_url' => 'https://example.com/integrations/stripe',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('SoftwareApplication');
        expect($schema['applicationCategory'])->toBe('WebApplication');
    });

    test('generates web application schema for calculator pages', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Calculators',
            'target_keyword' => 'development cost calculator',
            'meta_title' => 'Development Cost Calculator | Zao',
            'meta_description' => 'Calculate the cost of your development project.',
            'page_url' => 'https://example.com/tools/cost-calculator',
        ]);

        $schema = $this->service->getSchemaForPlaybook($page);

        expect($schema['@type'])->toBe('WebApplication');
        expect($schema['applicationCategory'])->toBe('UtilityApplication');
    });

    test('generates complete page schema with organization', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Vertical',
            'target_keyword' => 'wordpress development',
            'meta_title' => 'WordPress Development Services | Zao',
            'meta_description' => 'Expert WordPress development services.',
            'page_url' => 'https://example.com/wordpress',
        ]);

        $schema = $this->service->generateForPage($page);

        expect($schema['@context'])->toBe('https://schema.org');
        expect($schema['@graph'])->toBeArray();
        expect($schema['@graph'])->toHaveCount(2);

        // First should be organization
        expect($schema['@graph'][0]['@type'])->toBe('Organization');
        // Second should be page-specific schema
        expect($schema['@graph'][1]['@type'])->toBe('Service');
    });

    test('converts schema to script tag', function () {
        $page = SeoPage::factory()->create([
            'playbook' => 'Vertical',
            'target_keyword' => 'laravel development',
            'meta_title' => 'Laravel Development | Zao',
            'page_url' => 'https://example.com/laravel',
        ]);

        $schema = $this->service->generateForPage($page);
        $scriptTag = $this->service->toScriptTag($schema);

        expect($scriptTag)->toStartWith('<script type="application/ld+json">');
        expect($scriptTag)->toEndWith('</script>');
        expect($scriptTag)->toContain('"@context": "https://schema.org"');
    });

    test('extracts correct service type from keywords', function () {
        $laravelPage = SeoPage::factory()->create([
            'playbook' => 'Vertical',
            'target_keyword' => 'laravel saas development',
            'page_url' => 'https://example.com/laravel-saas',
        ]);

        $wordpressPage = SeoPage::factory()->create([
            'playbook' => 'Vertical',
            'target_keyword' => 'wordpress ecommerce',
            'page_url' => 'https://example.com/wordpress-ecommerce',
        ]);

        $laravelSchema = $this->service->getSchemaForPlaybook($laravelPage);
        $wordpressSchema = $this->service->getSchemaForPlaybook($wordpressPage);

        expect($laravelSchema['serviceType'])->toBe('Laravel Development');
        expect($wordpressSchema['serviceType'])->toBe('WordPress Development');
    });
});
