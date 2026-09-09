<?php

use App\Agents\Definitions\LeadGenerationAgent;

describe('LeadGenerationAgent', function () {
    beforeEach(function () {
        $this->agent = new LeadGenerationAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Lead Generation');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Proactive prospect research and ICP qualification for lead generation.');
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

    it('does not require approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeFalse();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('search_prospects')
            ->and($tools)->toContain('create_prospect')
            ->and($tools)->toContain('match_icp')
            ->and($tools)->toContain('web_search');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty()
            ->and($prompt)->toContain('Lead Generation');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('icp_id')
            ->and($schema)->toHaveKey('target_count')
            ->and($schema)->toHaveKey('industries')
            ->and($schema)->toHaveKey('min_score');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'prospects_created' => 5,
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('prospects_researched')
            ->and($output)->toHaveKey('prospects_created')
            ->and($output)->toHaveKey('prospects_qualified')
            ->and($output)->toHaveKey('avg_icp_score')
            ->and($output)->toHaveKey('prospects')
            ->and($output)->toHaveKey('search_insights')
            ->and($output)->toHaveKey('icp_recommendations')
            ->and($output['prospects_created'])->toBe(5);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('lead-generation');
    });
});
