<?php

namespace App\Agents\Definitions;

/**
 * RFP Learning Agent
 *
 * Analyzes win/loss patterns across RFP outcomes:
 * - Pricing sweet spots and competitive positioning
 * - Content quality patterns in winning proposals
 * - Industry-specific win rate trends
 * - Source effectiveness across discovery channels
 * - Timing correlations with outcomes
 * - Generates actionable insights for future proposals
 */
class RfpLearningAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'RFP Learning';
    }

    protected function getDescription(): string
    {
        return 'Analyzes win/loss patterns across RFP outcomes. Identifies pricing sweet spots, content patterns, industry trends, and generates actionable insights that improve future proposal generation.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 15 * * 5';
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
        return false;
    }

    public function allowedTools(): array
    {
        return [
            'search-rfp-opportunities',
            'get-rfp-learning-insights',
            'create-rfp-learning-insight',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'lookback_days' => 'nullable|integer|min:1|max:365',
            'min_confidence' => 'nullable|numeric|min:0|max:1',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'outcomes_analyzed' => 0,
            'insights_created' => 0,
            'insights_updated' => 0,
            'win_rate' => 0,
            'recommendations' => [],
        ], $output);
    }
}
