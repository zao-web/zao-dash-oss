<?php

use App\Agents\Definitions\ContentSchedulerAgent;

describe('ContentSchedulerAgent', function () {
    beforeEach(function () {
        $this->agent = new ContentSchedulerAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Content Scheduler');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Manage and schedule WordPress content publishing.');
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
        expect($this->agent->metadata()['max_budget_usd'])->toBe(3.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('search_content')
            ->and($tools)->toContain('web_search');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('site_id')
            ->and($schema)->toHaveKey('mode')
            ->and($schema)->toHaveKey('content_ids')
            ->and($schema)->toHaveKey('auto_schedule');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'content_reviewed' => 5,
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('content_reviewed')
            ->and($output)->toHaveKey('ready_to_publish')
            ->and($output)->toHaveKey('needs_revision')
            ->and($output)->toHaveKey('scheduled')
            ->and($output)->toHaveKey('calendar')
            ->and($output)->toHaveKey('recommendations')
            ->and($output['content_reviewed'])->toBe(5);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('content-scheduler');
    });
});
