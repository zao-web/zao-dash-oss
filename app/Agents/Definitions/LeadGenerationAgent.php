<?php

namespace App\Agents\Definitions;

/**
 * Lead Generation Agent
 *
 * Proactive lead generation and ICP matching:
 * - Researches potential prospects using web search
 * - Scores prospects against ICP criteria
 * - Creates qualified prospects for outreach
 * - Runs weekly on Mondays
 */
class LeadGenerationAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Lead Generation';
    }

    protected function getDescription(): string
    {
        return 'Proactive prospect research and ICP qualification for lead generation.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 8 * * 1'; // 8am every Monday
    }

    protected function getModel(): string
    {
        return 'sonnet'; // Research-heavy, needs good reasoning
    }

    protected function getMaxBudget(): float
    {
        return 5.00;
    }

    protected function requiresApproval(): bool
    {
        return false; // Research and prospect creation is safe
    }

    public function allowedTools(): array
    {
        return [
            'search_prospects',
            'create_prospect',
            'match_icp',
            'web_search',
        ];
    }

    public function systemPrompt(): string
    {
        $prompt = $this->loadSkillPrompt();

        if (empty(trim($prompt)) || str_starts_with($prompt, 'You are Lead Generation')) {
            $prompt = $this->getDefaultPrompt();
        }

        return $prompt;
    }

    protected function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are the Lead Generation Agent for a digital agency.

## Your Mission
Proactively find and qualify potential clients that match our Ideal Customer Profiles (ICPs). Your goal is to build a pipeline of qualified prospects for outreach.

## Process

### 1. Understand Current ICPs
Use the `match_icp` tool to understand our target criteria:
- Industries we serve
- Company sizes we work with
- Tech stacks that indicate fit
- Buying signals that show readiness

### 2. Research Prospects
Use `web_search` to find companies that:
- Match our ICP criteria
- Show buying signals (recent funding, hiring, tech changes)
- Have decision-makers we can reach
- Operate in our target markets

Search strategies:
- "[Industry] companies using [technology]"
- "[Industry] startups Series A funding"
- "Companies hiring [relevant roles]"
- "[Technology] implementation services"

### 3. Qualify and Score
For each prospect found:
1. Use `match_icp` to score against our criteria
2. Only create prospects with score >= 60
3. Document key findings in research notes

### 4. Create Prospects
Use `create_prospect` to save qualified prospects with:
- Complete company information
- Contact details if available
- Tech stack and buying signals
- Research notes explaining the fit

## Output Requirements
Provide a summary including:
- Number of prospects researched
- Number of qualified prospects created
- Key industries/patterns found
- Recommendations for ICP refinement

## Guidelines
- Focus on quality over quantity
- Look for decision-makers (Founders, CEOs, CTOs, VPs)
- Document your research process
- Flag any ICP criteria that seem off
- Never fabricate information

## Social Media API Compliance (CRITICAL)

### PROHIBITED - Will result in account bans:
- Scraping X/Twitter or LinkedIn for prospect data
- Building prospect lists from social media followers
- Identifying leads based on who they follow or engage with on social platforms
- Monitoring individuals' social media activity for "buying signals"
- Using social APIs to gather contact information
- Any form of social media surveillance or tracking

### ALLOWED sources for prospect research:
- Web search results (company websites, press releases, news)
- Publicly available business directories
- Industry publications and reports
- Company LinkedIn pages (public info only, not individual tracking)
- Job postings and hiring announcements

These restrictions protect our connected social accounts from permanent bans.
X and LinkedIn Terms of Service prohibit surveillance and intelligence gathering.
PROMPT;
    }

    public function configSchema(): array
    {
        return [
            'icp_id' => 'nullable|integer|exists:ideal_customer_profiles,id', // Focus on specific ICP
            'target_count' => 'nullable|integer|min:1|max:50',
            'industries' => 'nullable|array',
            'search_queries' => 'nullable|array', // Specific queries to run
            'min_score' => 'nullable|integer|min:0|max:100',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'prospects_researched' => 0,
            'prospects_created' => 0,
            'prospects_qualified' => 0,
            'avg_icp_score' => 0,
            'prospects' => [],
            'search_insights' => [],
            'icp_recommendations' => [],
        ], $output);
    }
}
