<?php

use App\Agents\Definitions\LeadNurtureAgent;

describe('LeadNurtureAgent', function () {
    beforeEach(function () {
        $this->agent = new LeadNurtureAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Lead Nurture');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Automated lead follow-up and nurturing sequences.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 9 * * 1-5');
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
            ->and($tools)->toContain('search_leads')
            ->and($tools)->toContain('search_clients')
            ->and($tools)->toContain('web_search');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('lead_id')
            ->and($schema)->toHaveKey('mode')
            ->and($schema)->toHaveKey('max_leads')
            ->and($schema)->toHaveKey('stages')
            ->and($schema)->toHaveKey('min_days_since_contact');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'leads_reviewed' => 10,
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('leads_reviewed')
            ->and($output)->toHaveKey('follow_ups')
            ->and($output)->toHaveKey('recommendations')
            ->and($output)->toHaveKey('leads_to_archive')
            ->and($output['leads_reviewed'])->toBe(10);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('lead-nurture');
    });
});
