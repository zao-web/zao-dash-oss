<?php

use App\Agents\Definitions\UpsellProposalAgent;

describe('UpsellProposalAgent', function () {
    beforeEach(function () {
        $this->agent = new UpsellProposalAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Upsell Proposal Drafter');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Draft personalized upsell proposals based on client signals and history.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
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

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('search_clients')
            ->and($tools)->toContain('search_projects')
            ->and($tools)->toContain('web_search');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('client')
            ->and($schema['client'])->toContain('required')
            ->and($schema)->toHaveKey('signal')
            ->and($schema)->toHaveKey('tone')
            ->and($schema)->toHaveKey('focus');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'subject' => 'Test Proposal',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('subject')
            ->and($output)->toHaveKey('proposal')
            ->and($output)->toHaveKey('talking_points')
            ->and($output)->toHaveKey('suggested_services')
            ->and($output)->toHaveKey('estimated_value')
            ->and($output)->toHaveKey('follow_up_date')
            ->and($output['subject'])->toBe('Test Proposal');
    });

    it('validates context with valid data', function () {
        $context = [
            'client' => 'test-client',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('upsell-proposal');
    });
});
