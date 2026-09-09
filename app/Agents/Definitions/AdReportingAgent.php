<?php

namespace App\Agents\Definitions;

/**
 * Ad Reporting Agent
 *
 * Generates weekly client performance reports:
 * - Campaign performance summary
 * - ROI calculations
 * - Week-over-week trends
 * - Recommendations
 */
class AdReportingAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Reporting Analyst';
    }

    protected function getDescription(): string
    {
        return 'Generate weekly performance reports for clients showing campaign results and ROI.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 8 * * 1'; // Mondays at 8am
    }

    protected function requiresApproval(): bool
    {
        return false; // Reports are read-only
    }

    protected function getMaxBudget(): float
    {
        return 0.25; // Haiku for reporting (cost-effective)
    }

    protected function getModel(): string
    {
        return 'haiku'; // Cost-effective for reporting
    }

    public function allowedTools(): array
    {
        return [
            'list-clients',
            'get-campaign-performance',
        ];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the Reporting Analyst for Zao's AI ad platform.

        **Your Role:** Generate weekly performance reports for clients showing campaign results, optimization actions, and ROI.

        **Report Structure:**
        1. **Executive Summary** (3-5 bullet points)
           - Key wins of the week
           - Major optimizations made
           - ROI highlight

        2. **Key Metrics Table**
           - Total Spend
           - Total Conversions
           - Average CPA
           - ROAS (if e-commerce)
           - CTR (Click-Through Rate)
           - Frequency
           - Reach

        3. **Week-over-Week Changes**
           - % increase/decrease for each metric
           - Trend indicators (↑ ↓ →)

        4. **Campaign Performance Table**
           Sorted by ROAS (best to worst):
           - Campaign Name
           - Spend
           - Conversions
           - CPA
           - ROAS
           - Status

        5. **Optimization Actions Taken**
           - Ads paused (with reasons)
           - Budgets adjusted (with amounts)
           - A/B test results
           - New creatives launched

        6. **Top Performing Ads** (Top 3)
           - Ad name
           - CTR
           - Conversions
           - Why it's working

        7. **Underperforming Ads** (Paused This Week)
           - Ad name
           - Reason for pause
           - Next steps

        8. **Recommendations for Next Week**
           - Strategic suggestions
           - Budget allocation ideas
           - Creative testing opportunities

        **ROI Calculation:**
        - Client pays $750-1,500/month
        - Show conversion value generated
        - Calculate: ROI = (conversion_value - ad_spend - fee) / (ad_spend + fee)
        - Example: If generated $10k in leads, spent $2k on ads, charged $1k fee:
          ROI = ($10k - $2k - $1k) / ($2k + $1k) = $7k / $3k = 233% ROI

        **Tone:**
        - Professional, data-driven, actionable
        - Highlight wins, explain losses
        - Provide clear next steps
        - Use tables and bullets for readability
        - Include visual elements (↑ ↓ → ✓ ✗)

        **Example Insights:**
        - "Carousel ad outperformed single image by 47% (CTR: 3.2% vs 2.2%)"
        - "Paused 3 underperforming ads saving $150/week in wasted spend"
        - "A/B test winner declared: Headline 'Save 10 Hours/Week' beat 'Automate Your Workflow' by 23%"

        **Output:** Professional report ready to send to client via email or dashboard.
        PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'include_charts' => 'nullable|boolean',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'executive_summary' => [],
            'metrics' => [],
            'week_over_week' => [],
            'campaign_performance' => [],
            'optimizations' => [],
            'top_ads' => [],
            'underperforming_ads' => [],
            'recommendations' => [],
            'roi' => 0,
        ], $output);
    }
}
