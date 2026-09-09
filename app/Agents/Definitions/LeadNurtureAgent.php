<?php

namespace App\Agents\Definitions;

/**
 * Lead Nurture Agent
 *
 * Automated lead nurturing and follow-up:
 * - Identifies leads needing attention
 * - Drafts personalized follow-up messages
 * - Suggests next actions based on lead stage
 * - Can run on schedule or manually
 */
class LeadNurtureAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Lead Nurture';
    }

    protected function getDescription(): string
    {
        return 'Automated lead follow-up and nurturing sequences.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 9 * * 1-5'; // 9am weekdays
    }

    protected function requiresApproval(): bool
    {
        return true; // Review before sending any outreach
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    public function allowedTools(): array
    {
        return [
            'search_leads',
            'search_clients',
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
            'lead_id' => 'nullable|integer|exists:leads,id', // Specific lead, or null for batch
            'mode' => 'nullable|in:single,batch,review',
            'max_leads' => 'nullable|integer|min:1|max:20',
            'stages' => 'nullable|array', // Filter by stages
            'min_days_since_contact' => 'nullable|integer|min:1',
            'min_deal_value' => 'nullable|numeric|min:0',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'leads_reviewed' => 0,
            'follow_ups' => [],
            'recommendations' => [],
            'leads_to_archive' => [],
        ], $output);
    }
}
