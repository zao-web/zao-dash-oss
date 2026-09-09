<?php

use App\Agents\Definitions\LandingPageGeneratorAgent;

describe('LandingPageGeneratorAgent', function () {
    beforeEach(function () {
        $this->agent = new LandingPageGeneratorAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Landing Page Generator');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Generate landing page copy targeting specific industries or verticals.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(5.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('industry')
            ->and($schema['industry'])->toContain('required')
            ->and($schema)->toHaveKey('services')
            ->and($schema)->toHaveKey('tone')
            ->and($schema)->toHaveKey('include_testimonials')
            ->and($schema)->toHaveKey('seo_keywords');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'headline' => 'Test Headline',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('headline')
            ->and($output)->toHaveKey('subheadline')
            ->and($output)->toHaveKey('hero_copy')
            ->and($output)->toHaveKey('value_propositions')
            ->and($output)->toHaveKey('sections')
            ->and($output)->toHaveKey('cta_text')
            ->and($output)->toHaveKey('meta_title')
            ->and($output)->toHaveKey('meta_description')
            ->and($output)->toHaveKey('suggested_images')
            ->and($output['headline'])->toBe('Test Headline');
    });

    it('validates context with valid data', function () {
        $context = [
            'industry' => 'SaaS',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('landing-page-generator');
    });
});
