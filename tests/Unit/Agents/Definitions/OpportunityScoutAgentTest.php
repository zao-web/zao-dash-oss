<?php

use App\Agents\Definitions\OpportunityScoutAgent;

describe('OpportunityScoutAgent', function () {
    beforeEach(function () {
        $this->agent = new OpportunityScoutAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Opportunity Scout');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Analyzes completed projects and client interactions to identify upsell opportunities, referral potential, and new market opportunities.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 10 * * 5');
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

    it('has tools defined', function () {
        $reflection = new \ReflectionClass($this->agent);
        $method = $reflection->getMethod('getTools');
        $method->setAccessible(true);
        $tools = $method->invoke($this->agent);

        expect($tools)->toBeArray()
            ->and($tools)->toContain('search-projects')
            ->and($tools)->toContain('search-clients')
            ->and($tools)->toContain('search-communications')
            ->and($tools)->toContain('get-quarterly-patterns')
            ->and($tools)->toContain('get-client-health')
            ->and($tools)->toContain('get-project-metrics')
            ->and($tools)->toContain('web-search')
            ->and($tools)->toContain('create-opportunity')
            ->and($tools)->toContain('create-task');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('lookback_days')
            ->and($schema)->toHaveKey('min_project_value')
            ->and($schema)->toHaveKey('focus_industries');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'opportunities' => ['opp1'],
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('opportunities')
            ->and($output)->toHaveKey('upsell_suggestions')
            ->and($output)->toHaveKey('referral_candidates')
            ->and($output)->toHaveKey('market_insights')
            ->and($output)->toHaveKey('action_items')
            ->and($output['opportunities'])->toBe(['opp1']);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('opportunity-scout');
    });
});
