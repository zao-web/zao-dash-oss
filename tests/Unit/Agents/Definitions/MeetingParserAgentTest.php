<?php

use App\Agents\Definitions\MeetingParserAgent;

describe('MeetingParserAgent', function () {
    beforeEach(function () {
        $this->agent = new MeetingParserAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Meeting Parser');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Parse meeting transcripts and extract action items, decisions, and follow-up tasks.');
    });

    it('returns webhook trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('webhook');
    });

    it('has no schedule for webhook agent', function () {
        expect($this->agent->metadata())->not->toHaveKey('schedule');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(2.00);
    });

    it('does not require approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeFalse();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('api_calls');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('transcript')
            ->and($schema['transcript'])->toContain('required')
            ->and($schema)->toHaveKey('meeting_title')
            ->and($schema)->toHaveKey('attendees')
            ->and($schema)->toHaveKey('project_id');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty();
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'action_items' => ['item1'],
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('action_items')
            ->and($output)->toHaveKey('decisions')
            ->and($output)->toHaveKey('follow_ups')
            ->and($output)->toHaveKey('summary')
            ->and($output['action_items'])->toBe(['item1'])
            ->and($output['decisions'])->toBe([])
            ->and($output['follow_ups'])->toBe([])
            ->and($output['summary'])->toBe('');
    });

    it('validates context correctly with valid data', function () {
        $context = [
            'transcript' => 'This is a test transcript with more than 50 characters to meet the minimum requirement.',
            'meeting_title' => 'Test Meeting',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('validates context correctly with invalid data', function () {
        $context = [
            'transcript' => 'Too short',
        ];

        expect($this->agent->validateContext($context))->toBeFalse();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('meeting-parser');
    });
});
