<?php

namespace App\Agents\Definitions;

/**
 * RFP Proposal Writer Agent
 *
 * Generates best-in-class RFP proposals by:
 * - Researching the target organization via web search
 * - Gathering relevant case studies and testimonials from past projects
 * - Searching Google Drive for reference proposals and capabilities decks
 * - Applying learned insights from past wins/losses
 * - Addressing every RFP requirement with evidence-backed responses
 */
class RfpProposalWriterAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'RFP Proposal Writer';
    }

    protected function getDescription(): string
    {
        return 'Generates best-in-class RFP proposals by researching the target organization, gathering relevant case studies and testimonials, applying learned insights from past wins/losses, and addressing every RFP requirement.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getChainFrom(): ?string
    {
        return 'rfp-evaluator';
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 10.00;
    }

    protected function requiresApproval(): bool
    {
        return true;
    }

    public function allowedTools(): array
    {
        return [
            'search-rfp-opportunities',
            'search',
            'search-google-drive',
            'web-search',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'rfp_opportunity_id' => 'required|integer|exists:rfp_opportunities,id',
            'model_override' => 'nullable|string|in:sonnet,opus',
            'tone_profile' => 'nullable|string|in:professional,consultative,technical',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'proposal_id' => null,
            'opportunity_id' => null,
            'sections_generated' => 0,
            'requirements_addressed' => 0,
            'case_studies_used' => [],
            'total_price' => null,
            'tone_profile' => 'professional',
        ], $output);
    }
}
