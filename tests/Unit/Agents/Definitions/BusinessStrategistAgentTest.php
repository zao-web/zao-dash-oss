<?php

use App\Agents\Definitions\BusinessStrategistAgent;

describe('BusinessStrategistAgent', function () {
    beforeEach(function () {
        $this->agent = new BusinessStrategistAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Business Strategist');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Strategic planning and agent orchestration for revenue goals.');
    });

    it('returns scheduled trigger', function () {
        expect($this->agent->metadata()['trigger'])->toBe('scheduled');
    });

    it('has correct schedule', function () {
        expect($this->agent->metadata())->toHaveKey('schedule')
            ->and($this->agent->metadata()['schedule'])->toBe('0 6 * * 1-5');
    });

    it('returns opus model', function () {
        expect($this->agent->metadata()['model'])->toBe('opus');
    });

    it('returns correct max budget', function () {
        expect($this->agent->metadata()['max_budget_usd'])->toBe(15.00);
    });

    it('requires approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeTrue();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('analyze_goal_progress')
            ->and($tools)->toContain('get_funnel_metrics')
            ->and($tools)->toContain('forecast_revenue')
            ->and($tools)->toContain('create_weekly_plan')
            ->and($tools)->toContain('assign_agent_task')
            ->and($tools)->toContain('search_prospects')
            ->and($tools)->toContain('search_leads');
    });

    it('returns system prompt', function () {
        $prompt = $this->agent->systemPrompt();
        expect($prompt)->toBeString()
            ->and($prompt)->not->toBeEmpty()
            ->and($prompt)->toContain('Business Strategist');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('goal_id')
            ->and($schema)->toHaveKey('mode')
            ->and($schema)->toHaveKey('focus_areas');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'weekly_plan_id' => 1,
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('mode')
            ->and($output)->toHaveKey('goal_progress')
            ->and($output)->toHaveKey('weekly_plan_id')
            ->and($output)->toHaveKey('tasks_assigned')
            ->and($output)->toHaveKey('alerts')
            ->and($output)->toHaveKey('recommendations')
            ->and($output['weekly_plan_id'])->toBe(1);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('business-strategist');
    });
});
