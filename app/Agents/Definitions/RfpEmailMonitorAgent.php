<?php

namespace App\Agents\Definitions;

/**
 * RFP Email Monitor Agent
 *
 * Scans recent emails from configured RFP sources and extracts
 * individual bid opportunities into the RFP pipeline:
 * - Parses multi-opportunity teaser emails
 * - Creates RfpOpportunity records for each listing
 * - Deduplicates against existing opportunities
 * - Extracts tech keywords, budget ranges, deadlines
 */
class RfpEmailMonitorAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'RFP Email Monitor';
    }

    protected function getDescription(): string
    {
        return 'Scans recent emails from configured RFP sources and extracts individual bid opportunities into the RFP pipeline.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '*/30 * * * *'; // Every 30 minutes
    }

    protected function getModel(): string
    {
        return 'haiku';
    }

    protected function getMaxBudget(): float
    {
        return 1.00;
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
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'source_ids' => 'nullable|array',
            'lookback_hours' => 'nullable|integer|min:1|max:168',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'emails_scanned' => 0,
            'opportunities_found' => 0,
            'opportunities_created' => 0,
            'duplicates_skipped' => 0,
            'opportunities' => [],
        ], $output);
    }
}
