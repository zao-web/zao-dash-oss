<?php

namespace App\Agents\Definitions;

/**
 * Client Health Monitor Agent
 *
 * Proactively monitors client health and flags issues:
 * - Detects declining health scores
 * - Analyzes sentiment signals from communications
 * - Identifies at-risk clients before they churn
 * - Recommends intervention strategies
 */
class ClientHealthMonitorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Client Health Monitor';
    }

    protected function getDescription(): string
    {
        return 'Monitor client health metrics and flag at-risk accounts.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 7 * * 1-5'; // 7am weekdays - early warning
    }

    protected function requiresApproval(): bool
    {
        return false; // Monitoring can run automatically
    }

    protected function getMaxBudget(): float
    {
        return 2.00;
    }

    public function allowedTools(): array
    {
        return [
            'search_clients',
            'search_projects',
            'search_tasks',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'client_id' => 'nullable|integer|exists:clients,id', // Specific client or all
            'threshold' => 'nullable|integer|min:0|max:100', // Health score threshold
            'include_healthy' => 'nullable|boolean', // Include healthy clients in report
            'depth' => 'nullable|in:summary,detailed',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'clients_analyzed' => 0,
            'at_risk' => [],
            'declining' => [],
            'healthy' => [],
            'alerts' => [],
            'recommended_actions' => [],
            'summary' => '',
        ], $output);
    }
}
