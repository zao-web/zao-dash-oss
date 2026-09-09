<?php

namespace App\Agents\Definitions;

/**
 * RFP Evaluator Agent
 *
 * Evaluates discovered RFP opportunities for agency fit:
 * - Scores tech stack match against agency capabilities
 * - Checks industry experience via past projects/clients
 * - Evaluates budget alignment and timeline feasibility
 * - Assesses win probability based on source and relationship
 * - Auto-qualifies (>=60), flags for review (30-59), or declines (<30)
 */
class RfpEvaluatorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'RFP Evaluator';
    }

    protected function getDescription(): string
    {
        return 'Evaluates discovered RFP opportunities for fit against agency capabilities, past work, and win probability. Scores 0-100 and auto-qualifies or declines.';
    }

    protected function getTrigger(): string
    {
        return 'chained';
    }

    protected function getChainFrom(): ?string
    {
        return 'rfp-email-monitor';
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
            'update-rfp-opportunity',
            'search-clients',
            'search-projects',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'rfp_opportunity_id' => 'nullable|integer|exists:rfp_opportunities,id',
            'min_score_to_qualify' => 'nullable|integer|min:0|max:100',
            'auto_decline_below' => 'nullable|integer|min:0|max:100',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'opportunities_evaluated' => 0,
            'qualified' => 0,
            'needs_review' => 0,
            'declined' => 0,
            'average_score' => 0,
            'evaluations' => [],
        ], $output);
    }
}
