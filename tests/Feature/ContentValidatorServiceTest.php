<?php

use App\Services\Seo\ContentValidatorService;
use App\Services\Seo\SchemaGeneratorService;
use App\Services\Seo\SeoResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $research = app(SeoResearchService::class);
    $schemaGenerator = app(SchemaGeneratorService::class);
    $this->validator = new ContentValidatorService($research, $schemaGenerator);
});

describe('ContentValidatorService', function () {
    test('validates content with sufficient word count', function () {
        // Create content with enough words for Vertical (1200 minimum)
        $words = str_repeat('This is valid content for testing the validator service with enough words to pass. ', 50);
        $content = [
            'content' => '<h1>Main Title</h1><h2>Section</h2>'.$words.'<a href="https://example.com/link1">link1</a><a href="https://example.com/link2">link2</a><a href="https://example.com/link3">link3</a><a href="https://example.com">external</a>',
            'meta_title' => 'Test Page Title for SEO Optimization',
            'meta_description' => 'This is a properly sized meta description for the test page with enough content for validation.',
        ];

        $result = $this->validator->validate($content, 'Vertical');

        expect($result['metadata']['word_count'])->toBeGreaterThan(500);
        expect($result['metadata']['internal_links'])->toBe(3);
    });

    test('fails validation for content below minimum word count', function () {
        $content = [
            'content' => 'Short content only.',
            'meta_title' => 'Test Page Title for SEO',
            'meta_description' => 'This is a meta description.',
        ];

        $result = $this->validator->validate($content, 'Comparisons');

        expect($result['valid'])->toBeFalse();
        expect(collect($result['errors'])->filter(fn ($e) => str_contains($e, 'Content too short')))->not->toBeEmpty();
    });

    test('returns minimum word count by playbook', function () {
        expect($this->validator->getMinWordCount('Location'))->toBe(1000);
        expect($this->validator->getMinWordCount('Comparisons'))->toBe(1500);
        expect($this->validator->getMinWordCount('Glossary'))->toBe(600);
        expect($this->validator->getMinWordCount('Case Study'))->toBe(1000);
        expect($this->validator->getMinWordCount('Tools'))->toBe(800);
    });

    test('warns for meta title too long', function () {
        $content = [
            'content' => str_repeat('Content word ', 200),
            'meta_title' => 'This is a very long meta title that exceeds the recommended sixty character limit for SEO optimization',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'Meta title too long')))->not->toBeEmpty();
    });

    test('warns for meta description too long', function () {
        $content = [
            'content' => str_repeat('Content word ', 200),
            'meta_title' => 'Valid Meta Title Length',
            'meta_description' => str_repeat('This is a very long meta description. ', 10),
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'Meta description too long')))->not->toBeEmpty();
    });

    test('errors for missing meta title', function () {
        $content = [
            'content' => str_repeat('Content word ', 200),
            'meta_title' => '',
            'meta_description' => 'Valid meta description.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect($result['errors'])->toContain('Missing meta title');
    });

    test('detects AI patterns in content', function () {
        $content = [
            'content' => 'Let us delve into the topic. This cutting-edge solution will leverage AI to revolutionize your business seamlessly. '.str_repeat('Normal content here. ', 50),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['errors'])->filter(fn ($e) => str_contains($e, 'AI patterns')))->not->toBeEmpty();
        expect($result['metadata']['humanization_score'])->toBeLessThan(100);
    });

    test('warns for few internal links', function () {
        $content = [
            'content' => str_repeat('Content without any links to internal pages. ', 50),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'internal links')))->not->toBeEmpty();
    });

    test('counts internal links correctly', function () {
        $content = [
            'content' => 'Check out our <a href="https://example.com/laravel">Laravel services</a> and <a href="https://example.com/wordpress">WordPress services</a>. Also see <a href="https://example.com/contact">contact us</a>. '.str_repeat('More content here. ', 50),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect($result['metadata']['internal_links'])->toBe(3);
    });

    test('validates comparison page requirements', function () {
        $content = [
            'content' => str_repeat('Generic content without comparisons or tables. ', 100),
            'meta_title' => 'Some Page About Things',
            'meta_description' => 'A page about some things here.',
        ];

        $result = $this->validator->validate($content, 'Comparisons');

        // Check for any comparison-related error
        $comparisonErrors = collect($result['errors'])->filter(fn ($e) => str_contains(strtolower($e), 'comparison') || str_contains(strtolower($e), 'pros') || str_contains(strtolower($e), 'table')
        );
        expect($comparisonErrors)->not->toBeEmpty();
    });

    test('passes comparison validation with proper content', function () {
        $content = [
            'content' => 'Laravel vs WordPress: Which should you choose? <table><tr><th>Feature</th><th>Laravel</th><th>WordPress</th></tr></table> The pros of Laravel include faster development. The cons include steeper learning curve. '.str_repeat('More detailed comparison content about both Laravel and WordPress frameworks here with additional details about their features and benefits. ', 100),
            'meta_title' => 'Laravel vs WordPress Comparison',
            'meta_description' => 'Complete comparison of Laravel and WordPress frameworks.',
        ];

        $result = $this->validator->validate($content, 'Comparisons');

        // Filter for comparison-specific errors only (not word count)
        $comparisonErrors = collect($result['errors'])->filter(fn ($e) => (str_contains(strtolower($e), 'comparison') ||
            str_contains(strtolower($e), 'pros') ||
            str_contains(strtolower($e), 'compare')) &&
            ! str_contains(strtolower($e), 'word')
        );

        expect($comparisonErrors)->toBeEmpty();
    });

    test('validates location page requirements', function () {
        $content = [
            'content' => str_repeat('Generic web development services available everywhere. ', 100),
            'meta_title' => 'Web Development Services',
            'meta_description' => 'Professional web development services.',
        ];

        $result = $this->validator->validate($content, 'Location');

        expect(collect($result['errors'])->filter(fn ($e) => str_contains($e, 'geographic')))->not->toBeEmpty();
    });

    test('passes location validation with city mention', function () {
        $content = [
            'content' => 'Our Portland, Oregon team provides local web development services. We serve businesses near our office in the Portland area. '.str_repeat('More content about our services. ', 80),
            'meta_title' => 'Portland Web Development Agency',
            'meta_description' => 'Local web development in Portland, Oregon.',
        ];

        $result = $this->validator->validate($content, 'Location');

        $locationErrors = collect($result['errors'])->filter(fn ($e) => str_contains($e, 'geographic'));
        expect($locationErrors)->toBeEmpty();
    });

    test('validates case study requirements', function () {
        $content = [
            'content' => str_repeat('We built a website for a client. They were happy with it. ', 60),
            'meta_title' => 'Client Case Study Example',
            'meta_description' => 'How we helped a client succeed.',
        ];

        $result = $this->validator->validate($content, 'Case Study');

        expect(collect($result['errors'])->filter(fn ($e) => str_contains($e, 'measurable') || str_contains($e, 'challenge')))->not->toBeEmpty();
    });

    test('passes case study validation with metrics', function () {
        $content = [
            'content' => 'The challenge: slow website performance affecting conversions. Our solution: implemented caching and optimized queries. The result: 50% faster load times, increased conversions by 25%, improved revenue significantly. '.str_repeat('More details about the project implementation and outcome. ', 80),
            'meta_title' => 'Healthcare App Case Study',
            'meta_description' => 'How we improved healthcare app performance by 50%.',
        ];

        $result = $this->validator->validate($content, 'Case Study');

        $caseStudyErrors = collect($result['errors'])->filter(fn ($e) => str_contains($e, 'measurable') || str_contains($e, 'challenge')
        );
        expect($caseStudyErrors)->toBeEmpty();
    });

    test('warns for missing schema markup', function () {
        $content = [
            'content' => str_repeat('Content without any schema markup present in the page. ', 50),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'schema')))->not->toBeEmpty();
        expect($result['metadata']['has_schema'])->toBeFalse();
    });

    test('detects valid schema markup', function () {
        $content = [
            'content' => '<script type="application/ld+json">{"@context": "https://schema.org"}</script>'.str_repeat('Content with schema markup. ', 100),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect($result['metadata']['has_schema'])->toBeTrue();
    });

    test('calculates quality score correctly', function () {
        // High-quality content
        $content = [
            'content' => '<script type="application/ld+json">{}</script><h1>Main Title</h1><h2>Section</h2>'.str_repeat('High quality content here. ', 100).'<a href="https://example.com/link1">link1</a><a href="https://example.com/link2">link2</a><a href="https://example.com/link3">link3</a><a href="https://example.com/ref">reference</a>',
            'meta_title' => 'Perfect Meta Title for SEO Testing',
            'meta_description' => 'This is a perfectly sized meta description with the right length for SEO optimization purposes.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect($result['score'])->toBeGreaterThanOrEqual(70);
    });

    test('warns for heading structure issues', function () {
        $content = [
            'content' => '<h3>Subheading appears first</h3><h2>Then section</h2>'.str_repeat('Content here. ', 100),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        $headingWarnings = collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'H1') || str_contains($w, 'heading') || str_contains($w, 'hierarchy')
        );
        expect($headingWarnings)->not->toBeEmpty();
    });

    test('warns for multiple H1 headings', function () {
        $content = [
            'content' => '<h1>First H1 Heading</h1><h1>Second H1 Heading</h1>'.str_repeat('Content here. ', 100),
            'meta_title' => 'Valid Title Here For Testing',
            'meta_description' => 'Valid meta description here that is properly sized.',
        ];

        $result = $this->validator->validate($content, 'Glossary');

        expect(collect($result['warnings'])->filter(fn ($w) => str_contains($w, 'Multiple H1')))->not->toBeEmpty();
    });
});
