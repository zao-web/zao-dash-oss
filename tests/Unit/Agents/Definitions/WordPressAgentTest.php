<?php

use App\Agents\Definitions\WordPressAgent;

describe('WordPressAgent', function () {
    beforeEach(function () {
        $this->agent = new WordPressAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('WordPress Publisher');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Publishes blog posts, case studies, and landing pages to the WordPress agency website. Handles formatting, images, and SEO.');
    });

    it('returns chained trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('chained');
    });

    it('has chain_from metadata', function () {
        expect($this->agent->metadata())->toHaveKey('chain_from')
            ->and($this->agent->metadata()['chain_from'])->toBe('content-creator');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(3.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('word-press');
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
