<?php

use App\Agents\Definitions\QAAgent;

describe('QAAgent', function () {
    beforeEach(function () {
        $this->agent = new QAAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('QA Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Validate code changes, run tests, and check for quality and security issues.');
    });

    it('returns chained trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('chained');
    });

    it('has chain_from metadata', function () {
        expect($this->agent->metadata())->toHaveKey('chain_from')
            ->and($this->agent->metadata()['chain_from'])->toBe('dev-agent');
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

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('code_exec')
            ->and($tools)->toContain('file_ops');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('dev_agent_run_id')
            ->and($schema)->toHaveKey('files_changed')
            ->and($schema)->toHaveKey('test_suite');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'tests_passed' => true,
            'test_results' => ['All tests pass'],
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('tests_passed')
            ->and($output)->toHaveKey('test_results')
            ->and($output)->toHaveKey('quality_issues')
            ->and($output)->toHaveKey('security_issues')
            ->and($output)->toHaveKey('ready_to_merge')
            ->and($output)->toHaveKey('review_notes')
            ->and($output['tests_passed'])->toBeTrue();
    });

    it('has correct metadata id override', function () {
        expect($this->agent->metadata()['id'])->toBe('qa-agent');
    });
});
