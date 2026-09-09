<?php

use App\Agents\Definitions\BookkeepingAgent;

describe('BookkeepingAgent', function () {
    beforeEach(function () {
        $this->agent = new BookkeepingAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Bookkeeping Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Reviews and categorizes expenses in QuickBooks for tax optimization. Identifies uncategorized expenses, suggests appropriate categories, and applies categorizations with approval.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 7 * * 1-5');
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
        expect($this->agent->metadata()['id'])->toBe('bookkeeping');
    });

    it('has tools defined', function () {
        $reflection = new \ReflectionClass($this->agent);
        $method = $reflection->getMethod('getTools');
        $method->setAccessible(true);
        $tools = $method->invoke($this->agent);

        expect($tools)->toBeArray()
            ->and($tools)->toContain('qbo-get-expenses')
            ->and($tools)->toContain('qbo-get-categories')
            ->and($tools)->toContain('qbo-suggest-category')
            ->and($tools)->toContain('qbo-categorize-expense');
    });

    it('returns build system prompt', function () {
        $prompt = $this->agent->buildSystemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty();
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
