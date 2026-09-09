<?php

use App\Agents\Definitions\DevAgent;

describe('DevAgent', function () {
    beforeEach(function () {
        $this->agent = new DevAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Dev Agent');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Generate code changes from task specifications. Chains to QA for validation.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
    });

    it('returns correct model', function () {
        expect($this->agent->metadata()['model'])->toBe('sonnet');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(10.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('code_exec')
            ->and($tools)->toContain('github')
            ->and($tools)->toContain('file_ops');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('task_description')
            ->and($schema['task_description'])->toContain('required')
            ->and($schema)->toHaveKey('repository')
            ->and($schema)->toHaveKey('branch')
            ->and($schema)->toHaveKey('file_scope');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'files_changed' => ['file1.php'],
            'commit_message' => 'Test commit',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('files_changed')
            ->and($output)->toHaveKey('tests_added')
            ->and($output)->toHaveKey('commit_message')
            ->and($output)->toHaveKey('pr_description')
            ->and($output)->toHaveKey('needs_review')
            ->and($output['files_changed'])->toBe(['file1.php'])
            ->and($output['needs_review'])->toBeTrue();
    });

    it('validates context with valid data', function () {
        $context = [
            'task_description' => 'Implement feature X with proper error handling and tests',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('validates context rejects short description', function () {
        $context = [
            'task_description' => 'Too short',
        ];

        expect($this->agent->validateContext($context))->toBeFalse();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('dev');
    });
});
