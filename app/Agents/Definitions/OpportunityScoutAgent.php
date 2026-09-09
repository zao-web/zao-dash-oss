<?php

namespace App\Agents\Definitions;

/**
 * Opportunity Scout Agent
 *
 * Identifies growth opportunities from completed work:
 * - Upsell opportunities within existing clients
 * - Cross-sell to related services
 * - Referral potential from satisfied clients
 * - New market opportunities based on work patterns
 */
class OpportunityScoutAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Opportunity Scout';
    }

    protected function getDescription(): string
    {
        return 'Analyzes completed projects and client interactions to identify upsell opportunities, referral potential, and new market opportunities.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 10 * * 5'; // Friday 10am - end of week analysis
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Outreach suggestions need review
    }

    public function allowedTools(): array
    {
        return [
            'search-projects',
            'search-clients',
            'search-communications',
            'get-quarterly-patterns',
            'get-client-health',
            'get-project-metrics',
            'web-search',
            'create-opportunity',
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
            'lookback_days' => 'nullable|integer|min:7|max:90',
            'min_project_value' => 'nullable|numeric|min:0',
            'focus_industries' => 'nullable|array',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'opportunities' => [],
            'upsell_suggestions' => [],
            'referral_candidates' => [],
            'market_insights' => [],
            'action_items' => [],
        ], $output);
    }
}
