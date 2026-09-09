<?php

use App\Agents\Definitions\CommunicationAgent;

describe('CommunicationAgent', function () {
    beforeEach(function () {
        $this->agent = new CommunicationAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Communication Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Draft professional emails, Slack messages, and client communications.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(2.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('email')
            ->and($tools)->toContain('slack');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('type')
            ->and($schema['type'])->toContain('required')
            ->and($schema)->toHaveKey('recipient')
            ->and($schema)->toHaveKey('context')
            ->and($schema)->toHaveKey('tone');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'subject' => 'Test Subject',
            'body' => 'Test Body',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('subject')
            ->and($output)->toHaveKey('body')
            ->and($output)->toHaveKey('formatted_body')
            ->and($output)->toHaveKey('attachments')
            ->and($output)->toHaveKey('suggested_send_time')
            ->and($output['subject'])->toBe('Test Subject')
            ->and($output['attachments'])->toBe([]);
    });

    it('validates context with valid data', function () {
        $context = [
            'type' => 'email',
            'recipient' => 'test@example.com',
            'context' => 'Need to follow up on project status',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('communication');
    });
});
