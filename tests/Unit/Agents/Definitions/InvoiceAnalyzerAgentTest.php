<?php

use App\Agents\Definitions\InvoiceAnalyzerAgent;

describe('InvoiceAnalyzerAgent', function () {
    beforeEach(function () {
        $this->agent = new InvoiceAnalyzerAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Invoice Analyzer');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Analyzes time entries from Harvest, identifies billable work, and generates invoice drafts for review.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 9 * * 1');
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
        expect($this->agent->metadata()['id'])->toBe('invoice-analyzer');
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
