<?php

use App\Agents\Definitions\OutreachCampaignAgent;

describe('OutreachCampaignAgent', function () {
    beforeEach(function () {
        $this->agent = new OutreachCampaignAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Outreach Campaign');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Personalized multi-channel outreach and campaign management.');
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
            ->and($tools)->toContain('search_prospects')
            ->and($tools)->toContain('draft_outreach_message')
            ->and($tools)->toContain('schedule_follow_up')
            ->and($tools)->toContain('web_search');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty()
            ->and($prompt)->toContain('Outreach Campaign');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('campaign_id')
            ->and($schema)->toHaveKey('prospect_id')
            ->and($schema)->toHaveKey('mode')
            ->and($schema)->toHaveKey('max_messages')
            ->and($schema)->toHaveKey('channel');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'messages_drafted' => 3,
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('messages_drafted')
            ->and($output)->toHaveKey('follow_ups_scheduled')
            ->and($output)->toHaveKey('prospects_contacted')
            ->and($output)->toHaveKey('messages')
            ->and($output)->toHaveKey('skipped')
            ->and($output)->toHaveKey('insights')
            ->and($output['messages_drafted'])->toBe(3);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('outreach-campaign');
    });
});
