<?php

use App\Agents\Definitions\MarketingAgent;

describe('MarketingAgent', function () {
    beforeEach(function () {
        $this->agent = new MarketingAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Marketing Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Creates LinkedIn and X content plans, suggests growth campaigns based on completed work, and drafts social media posts.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 8 * * 1');
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

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('marketing');
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
