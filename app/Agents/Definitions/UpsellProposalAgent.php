<?php

namespace App\Agents\Definitions;

/**
 * Upsell Proposal Drafter Agent
 *
 * Creates personalized upsell proposals based on:
 * - Client history and current projects
 * - Detected signals (Slack mentions of budget/expansion)
 * - Service offerings and pricing
 * - Relationship context
 */
class UpsellProposalAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Upsell Proposal Drafter';
    }

    protected function getDescription(): string
    {
        return 'Draft personalized upsell proposals based on client signals and history.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true; // Always review before sending
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    public function allowedTools(): array
    {
        return [
            'search_clients',
            'search_projects',
            'web_search',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt(includeToneGuide: true);
    }

    public function configSchema(): array
    {
        return [
            'client' => 'required|string', // Client slug or name
            'signal' => 'nullable|string', // The signal that triggered this (e.g., "mentioned budget expansion")
            'tone' => 'nullable|in:professional,friendly,consultative',
            'focus' => 'nullable|string', // Specific service to focus on
            'additional_context' => 'nullable|string',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'subject' => '',
            'proposal' => '',
            'talking_points' => [],
            'suggested_services' => [],
            'estimated_value' => null,
            'follow_up_date' => null,
        ], $output);
    }
}
