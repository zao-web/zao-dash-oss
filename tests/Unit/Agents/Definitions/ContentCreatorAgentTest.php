<?php

use App\Agents\Definitions\ContentCreatorAgent;

describe('ContentCreatorAgent', function () {
    beforeEach(function () {
        $this->agent = new ContentCreatorAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Content Creator');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Generate case studies, blog posts, and marketing content from project data.');
    });

    it('returns manual trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('manual');
    });

    it('has no schedule for manual agent', function () {
        expect($this->agent->metadata())->not->toHaveKey('schedule');
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
            ->and($tools)->toContain('web_search')
            ->and($tools)->toContain('file_ops');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('content_type')
            ->and($schema['content_type'])->toContain('required')
            ->and($schema)->toHaveKey('project_id')
            ->and($schema)->toHaveKey('client_id')
            ->and($schema)->toHaveKey('topic')
            ->and($schema)->toHaveKey('tone')
            ->and($schema)->toHaveKey('target_length');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty();
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'title' => 'Test Title',
            'content' => 'Test Content',
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('title')
            ->and($output)->toHaveKey('content')
            ->and($output)->toHaveKey('summary')
            ->and($output)->toHaveKey('tags')
            ->and($output)->toHaveKey('meta_description')
            ->and($output['title'])->toBe('Test Title')
            ->and($output['content'])->toBe('Test Content')
            ->and($output['tags'])->toBe([]);
    });

    it('validates context correctly with valid data', function () {
        $context = [
            'content_type' => 'blog_post',
            'topic' => 'Test Topic',
        ];

        expect($this->agent->validateContext($context))->toBeTrue();
    });

    it('validates context correctly with invalid content type', function () {
        $context = [
            'content_type' => 'invalid_type',
            'topic' => 'Test Topic',
        ];

        expect($this->agent->validateContext($context))->toBeFalse();
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('content-creator');
    });
});
