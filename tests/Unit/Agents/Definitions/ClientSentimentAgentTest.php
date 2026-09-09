<?php

use App\Agents\Definitions\ClientSentimentAgent;

describe('ClientSentimentAgent', function () {
    beforeEach(function () {
        $this->agent = new ClientSentimentAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Client Sentiment');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Analyzes client communications to detect sentiment shifts, satisfaction signals, and potential escalation risks.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 8 * * 1-5');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(3.00);
    });

    it('does not require approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeFalse();
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('client_ids')
            ->and($schema)->toHaveKey('lookback_days')
            ->and($schema)->toHaveKey('include_slack')
            ->and($schema)->toHaveKey('include_email');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'alerts' => ['alert1'],
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('client_sentiments')
            ->and($output)->toHaveKey('alerts')
            ->and($output)->toHaveKey('trend_changes')
            ->and($output)->toHaveKey('escalation_risks')
            ->and($output['alerts'])->toBe(['alert1']);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('client-sentiment');
    });
});
