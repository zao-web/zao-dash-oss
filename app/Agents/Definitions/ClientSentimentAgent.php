<?php

namespace App\Agents\Definitions;

/**
 * Client Sentiment Agent
 *
 * Analyzes client communications to detect sentiment and emotional signals.
 * Distinct from ClientHealthMonitorAgent which tracks operational metrics.
 *
 * Focus areas:
 * - Email tone analysis
 * - Slack message sentiment
 * - Meeting transcript mood
 * - Escalation risk detection
 */
class ClientSentimentAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Client Sentiment';
    }

    protected function getDescription(): string
    {
        return 'Analyzes client communications to detect sentiment shifts, satisfaction signals, and potential escalation risks.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 8 * * 1-5'; // Weekdays 8am
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    protected function requiresApproval(): bool
    {
        return false; // Analysis only, no external actions
    }

    public function allowedTools(): array
    {
        return [
            'search-emails',
            'search-slack-messages',
            'get-recent-communications',
            'search-clients',
            'get-client-projects',
            'update-client-sentiment',
            'create-alert',
            'create-task',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'client_ids' => 'nullable|array',
            'lookback_days' => 'nullable|integer|min:1|max:30',
            'include_slack' => 'nullable|boolean',
            'include_email' => 'nullable|boolean',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'client_sentiments' => [],
            'alerts' => [],
            'trend_changes' => [],
            'escalation_risks' => [],
        ], $output);
    }
}
