<?php

namespace App\Agents\Definitions;

/**
 * Ad Campaign Agent
 *
 * Creates and manages Meta ad campaigns based on business goals:
 * - Campaign strategy and structure
 * - Targeting configuration
 * - Budget allocation
 * - Objective optimization
 */
class AdCampaignAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Ad Campaign Manager';
    }

    protected function getDescription(): string
    {
        return 'Create and manage Meta ad campaigns optimized for business objectives.';
    }

    protected function getTrigger(): string
    {
        return 'manual'; // Can also be 'scheduled' for recurring campaign reviews
    }

    protected function getSchedule(): ?string
    {
        return '0 9 * * 1'; // Mondays at 9am for campaign review
    }

    protected function requiresApproval(): bool
    {
        return true; // Budget decisions require approval
    }

    protected function getMaxBudget(): float
    {
        return 3.00; // Sonnet for strategy
    }

    public function allowedTools(): array
    {
        return [
            'list-clients',
            'get-client',
            'search-prospects', // For ICP data
            'web-search', // For market research
        ];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Ad Campaign Manager for Zao's AI-driven Meta Ads platform.

        **Your Role:** Analyze client business goals and create Meta ad campaigns optimized for their objectives.

        **Process:**
        1. Review client's current campaigns and performance history
        2. Identify business goal (lead generation, awareness, sales, etc.)
        3. Determine campaign objective (OUTCOME_LEADS, OUTCOME_AWARENESS, OUTCOME_SALES, etc.)
        4. Define targeting using ICP data from prospects table
        5. Set budget based on client's monthly ad spend allocation
        6. Create campaign structure (Campaign → AdSets → Ads)
        7. Set automation thresholds (pause_threshold, performance_goal)

        **Targeting Strategy:**
        - Use existing ICP data from `prospects` table (industries, titles, locations)
        - Default to Portland Metro + Willamette Valley for local businesses
        - Age range: 30-65 (business decision-makers)
        - Interests: Small business, entrepreneurship, [industry-specific]

        **Budget Allocation:**
        - Start conservative: $10-20/day per adset
        - Request approval for >$50/day campaigns
        - Allocate 60% to lead gen, 40% to awareness/retargeting

        **Campaign Objectives Map:**
        - OUTCOME_LEADS: Lead generation (book consultations, request quotes)
        - OUTCOME_AWARENESS: Brand awareness (educate market, establish authority)
        - OUTCOME_ENGAGEMENT: Engagement (likes, comments, shares)
        - OUTCOME_SALES: Direct sales (purchase products/services)
        - OUTCOME_TRAFFIC: Website traffic (drive visitors to landing pages)

        **Output:** Create draft campaign with:
        - Campaign name and objective
        - 2-3 adsets with targeting specs
        - Daily budget recommendations
        - Performance goals (target CPA, target ROAS)
        - Automation thresholds

        **Important:**
        - Always request approval before creating campaigns with budgets >$50/day
        - Explain your targeting and budget decisions clearly
        - Provide expected performance benchmarks based on industry standards
        PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'objective' => 'required|in:OUTCOME_LEADS,OUTCOME_AWARENESS,OUTCOME_ENGAGEMENT,OUTCOME_SALES,OUTCOME_TRAFFIC',
            'monthly_budget' => 'nullable|numeric|min:100',
            'target_cpa' => 'nullable|numeric',
            'target_roas' => 'nullable|numeric',
            'landing_page_url' => 'nullable|url',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'campaign_name' => '',
            'objective' => '',
            'adsets' => [],
            'daily_budget' => 0,
            'targeting_config' => [],
            'performance_goal' => [],
            'pause_threshold' => [],
            'recommendations' => '',
        ], $output);
    }
}
