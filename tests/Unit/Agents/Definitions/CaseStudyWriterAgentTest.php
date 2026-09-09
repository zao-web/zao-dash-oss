<?php

use App\Agents\Definitions\CaseStudyWriterAgent;

describe('CaseStudyWriterAgent', function () {
    beforeEach(function () {
        $this->agent = new CaseStudyWriterAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Case Study Writer');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Create compelling case studies from completed project data.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
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
            ->and($tools)->toContain('search_clients')
            ->and($tools)->toContain('search_projects')
            ->and($tools)->toContain('search_tasks')
            ->and($tools)->toContain('web_search');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('project_id')
            ->and($schema)->toHaveKey('client')
            ->and($schema)->toHaveKey('focus')
            ->and($schema)->toHaveKey('format')
            ->and($schema)->toHaveKey('tone');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'title' => 'Test Case Study',
            'challenge' => 'Test Challenge',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('title')
            ->and($output)->toHaveKey('summary')
            ->and($output)->toHaveKey('challenge')
            ->and($output)->toHaveKey('solution')
            ->and($output)->toHaveKey('results')
            ->and($output)->toHaveKey('metrics')
            ->and($output)->toHaveKey('quotes')
            ->and($output)->toHaveKey('full_content')
            ->and($output)->toHaveKey('social_snippet')
            ->and($output)->toHaveKey('suggested_visuals')
            ->and($output)->toHaveKey('tags')
            ->and($output['title'])->toBe('Test Case Study');
    });

    it('validates context with valid data', function () {
        $context = [
            'client' => 'Test Client',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('case-study-writer');
    });
});
