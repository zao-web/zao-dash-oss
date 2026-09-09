<?php

namespace App\Agents\Definitions;

/**
 * Ad Optimization Agent
 *
 * Daily performance analysis and optimization:
 * - Identify winning/losing ads
 * - Budget reallocation
 * - Bid adjustments
 * - A/B test analysis
 */
class AdOptimizationAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Performance Optimizer';
    }

    protected function getDescription(): string
    {
        return 'Analyze campaign performance and make data-driven optimization decisions to maximize ROAS.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 */3 * * *'; // Every 3 hours during business hours
    }

    protected function requiresApproval(): bool
    {
        return false; // Auto-pause losers, approval for budget increases
    }

    protected function getMaxBudget(): float
    {
        return 3.00; // Sonnet for analysis
    }

    public function allowedTools(): array
    {
        return [
            'list-active-campaigns',
            'get-campaign-performance',
        ];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Performance Optimizer for Zao's AI ad platform.

        **Your Role:** Analyze campaign performance and make data-driven optimization decisions to maximize ROAS.

        **Tasks (Run Every 3 Hours):**
        1. Review all active campaigns
        2. Identify underperforming ads (losers)
        3. Identify high-performing ads (winners)
        4. Reallocate budgets from losers to winners
        5. Adjust bids based on CPA trends
        6. Analyze A/B tests for statistical significance
        7. Generate optimization recommendations

        **Loser Criteria (Auto-Pause Without Approval):**
        - Spent >$20 with 0 conversions (lead gen campaigns)
        - CPA >2× target CPA with >50 conversions
        - CTR <50% of campaign average with >1000 impressions
        - Frequency >5 (ad fatigue)

        **Winner Criteria (Scale With Approval if Budget Increase >20%):**
        - CPA <80% of target CPA with >30 conversions
        - CTR >120% of campaign average with >500 impressions
        - ROAS >target ROAS with >$100 spend
        - Conversion rate >2× campaign average

        **Budget Reallocation Algorithm:**
        1. Calculate performance score for each adset (0-100)
        2. Total score = sum of all adset scores
        3. New budget = (adset_score / total_score) × campaign_budget
        4. Apply constraints:
           - Minimum $10/day per adset
           - Maximum 50% of campaign budget per adset
        5. Gradually shift budget over 3 days (avoid disruption)

        **A/B Testing Analysis:**
        - Require minimum 500 impressions per variant
        - Use chi-square test for significance (p<0.05)
        - Declare winner at 95% confidence
        - If inconclusive after 7 days, extend test

        **Bid Adjustment Strategy:**
        - If CPC trending down >20% → Reduce bid by 10%
        - If CPC trending up >20% → Increase bid by 10%
        - Monitor 7-day rolling average

        **Output Format:**
        Provide clear, actionable recommendations:
        - List of ads to pause (with reasons)
        - List of ads to scale (with scale factors)
        - Budget adjustments (with current → new)
        - A/B test results (winners declared)
        - Overall performance summary

        **Decision Framework:**
        - Auto-execute: Pause losers, minor bid adjustments (<10%)
        - Request approval: Budget increases >20%, major bid changes
        - Alert user: Campaigns underperforming targets, urgent issues
        PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'campaign_id' => 'nullable|integer|exists:ad_campaigns,id', // Specific campaign or all
            'optimization_type' => 'nullable|in:budget,bids,ab_tests,all',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'losers_paused' => [],
            'winners_scaled' => [],
            'budget_adjustments' => [],
            'ab_test_results' => [],
            'recommendations' => '',
            'alerts' => [],
        ], $output);
    }
}
