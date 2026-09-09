<?php

use App\Agents\Definitions\ClientHealthMonitorAgent;

describe('ClientHealthMonitorAgent', function () {
    beforeEach(function () {
        $this->agent = new ClientHealthMonitorAgent;
    });

    it('returns correct name', function () {
        expect($this->agent->metadata()['name'])->toBe('Client Health Monitor');
    });

    it('returns correct description', function () {
        expect($this->agent->metadata()['description'])
            ->toBe('Monitor client health metrics and flag at-risk accounts.');
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
        expect($this->agent->metadata()['max_budget_usd'])->toBe(2.00);
    });

    it('does not require approval', function () {
        expect($this->agent->metadata()['requires_approval'])->toBeFalse();
    });

    it('returns valid tool list', function () {
        $tools = $this->agent->allowedTools();
        expect($tools)->toBeArray()
            ->and($tools)->toContain('search_clients')
            ->and($tools)->toContain('search_projects')
            ->and($tools)->toContain('search_tasks');
    });

    it('has valid config schema', function () {
        $schema = $this->agent->configSchema();
        expect($schema)->toBeArray()
            ->and($schema)->toHaveKey('client_id')
            ->and($schema)->toHaveKey('threshold')
            ->and($schema)->toHaveKey('include_healthy')
            ->and($schema)->toHaveKey('depth');
    });

    it('processes output with correct structure', function () {
        $output = $this->agent->processOutput([
            'clients_analyzed' => 5,
            'at_risk' => ['client1'],
        ]);

        expect($output)->toBeArray()
            ->and($output)->toHaveKey('clients_analyzed')
            ->and($output)->toHaveKey('at_risk')
            ->and($output)->toHaveKey('declining')
            ->and($output)->toHaveKey('healthy')
            ->and($output)->toHaveKey('alerts')
            ->and($output)->toHaveKey('recommended_actions')
            ->and($output)->toHaveKey('summary')
            ->and($output['clients_analyzed'])->toBe(5);
    });

    it('has correct metadata id', function () {
        expect($this->agent->metadata()['id'])->toBe('client-health-monitor');
    });
});
