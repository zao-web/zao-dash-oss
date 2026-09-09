<?php

namespace App\Agents\Definitions;

/**
 * RFP Aggregator Agent
 *
 * Discovers RFP opportunities from government procurement sites,
 * industry boards, and web searches:
 * - Queries SAM.gov, state procurement portals, and bidding platforms
 * - Filters for web development relevance (WordPress, Laravel, CMS, design)
 * - Deduplicates against existing pipeline
 * - Creates opportunities with proper source tracking
 */
class RfpAggregatorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'RFP Aggregator';
    }

    protected function getDescription(): string
    {
        return 'Discovers RFP opportunities from government procurement sites, industry boards, and web searches. Filters for web development relevance.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 */4 * * *'; // Every 4 hours
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
            'create-rfp-opportunity',
            'web-search',
            'fetch-rfp-listing',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'keywords' => 'nullable|array',
            'min_budget' => 'nullable|numeric|min:0',
            'max_budget' => 'nullable|numeric|min:0',
            'regions' => 'nullable|array',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'sources_checked' => 0,
            'opportunities_found' => 0,
            'opportunities_created' => 0,
            'duplicates_skipped' => 0,
            'notable_opportunities' => [],
        ], $output);
    }
}
