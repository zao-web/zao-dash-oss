<?php

use App\Agents\Definitions\ProgrammaticSeoAgent;

describe('ProgrammaticSeoAgent', function () {
    beforeEach(function () {
        $this->agent = new ProgrammaticSeoAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Programmatic SEO Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toContain('Generates SEO-optimized landing pages and content at scale');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 7 * * 1');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(8.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('programmatic-seo');
    });

    it('has tools defined', function () {
        $tools = $this->agent->allowedTools();

        expect($tools)->toBeArray()
            ->and($tools)->toContain('seo-keyword-research')
            ->and($tools)->toContain('seo-analyze-serp')
            ->and($tools)->toContain('seo-competitor-gaps')
            ->and($tools)->toContain('seo-search-volume')
            ->and($tools)->toContain('seo-humanize-content')
            ->and($tools)->toContain('seo-performance-feedback')
            ->and($tools)->toContain('get-x-trends')
            ->and($tools)->toContain('web-search')
            ->and($tools)->toContain('search-projects')
            ->and($tools)->toContain('search-clients')
            ->and($tools)->toContain('get-quarterly-patterns')
            ->and($tools)->toContain('seo-generate-landing')
            ->and($tools)->toContain('seo-generate-blog')
            ->and($tools)->toContain('seo-optimize-content')
            ->and($tools)->toContain('seo-get-rankings')
            ->and($tools)->toContain('seo-track-page')
            ->and($tools)->toContain('seo-get-pseo-performance')
            ->and($tools)->toContain('seo-get-conversions')
            ->and($tools)->toContain('wp-create-page')
            ->and($tools)->toContain('wp-create-post');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty();
    });

    it('has empty config schema by default', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray();
    });

    it('processes output correctly', function () {
        $output = $this->agent->processOutput(['test' => 'value']);
        expect($output)->toBeArray()
            ->and($output)->toHaveKey('test');
    });
});
