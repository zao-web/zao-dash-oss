<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class BusinessBriefingPrompt extends Prompt
{
    protected string $name = 'business-briefing';

    protected string $description = 'Generate a comprehensive business briefing with current KPIs, active work, and priorities.';

    public function handle(Request $request): Response
    {
        $focus = $request->get('focus', 'general');

        $prompt = match ($focus) {
            'sales' => $this->salesBriefing(),
            'projects' => $this->projectsBriefing(),
            'financial' => $this->financialBriefing(),
            default => $this->generalBriefing(),
        };

        return Response::text($prompt);
    }

    public function arguments(): array
    {
        return [
            new Argument(
                name: 'focus',
                description: 'Focus area: general, sales, projects, or financial',
                required: false
            ),
        ];
    }

    private function generalBriefing(): string
    {
        return <<<'MARKDOWN'
# Business Briefing Request

Please provide a comprehensive business briefing by:

1. **Get Dashboard KPIs** - Use the dashboard resource to fetch current metrics
2. **Review Active Projects** - List projects with status = active
3. **Check Client Health** - List clients sorted by health score, identify any concerns
4. **Pipeline Status** - List leads in the pipeline, calculate weighted value
5. **Agent Activity** - Check for any pending approvals or recent agent runs

Summarize findings in this format:

## Executive Summary
[2-3 sentence overview of current business state]

## Key Metrics
- Revenue MTD: $X
- Active Projects: N
- Pipeline Value: $X
- Client Health: X% average

## Priorities Today
1. [Most urgent item]
2. [Second priority]
3. [Third priority]

## Concerns & Blockers
- [Any issues that need attention]

## Recommended Actions
- [Specific actions to take]
MARKDOWN;
    }

    private function salesBriefing(): string
    {
        return <<<'MARKDOWN'
# Sales Pipeline Briefing

Please analyze the sales pipeline:

1. **List all leads** in the pipeline
2. **Calculate metrics**:
   - Total pipeline value
   - Weighted pipeline value (value × probability)
   - Leads by stage
3. **Identify hot deals** - leads in proposal or negotiation stage
4. **Find stale leads** - leads not contacted recently
5. **Review conversion rate** - won vs lost ratio

Provide recommendations for:
- Which leads to prioritize
- Follow-up actions needed
- Pipeline health assessment
MARKDOWN;
    }

    private function projectsBriefing(): string
    {
        return <<<'MARKDOWN'
# Projects Status Briefing

Please review all active projects:

1. **List active projects** with their completion percentages
2. **Identify blockers**:
   - Tasks in review status waiting
   - Overdue tasks
   - Unassigned tasks
3. **Budget status** - projects approaching or over budget
4. **Upcoming milestones** - due within next 2 weeks

For each project with issues, recommend specific actions to unblock progress.
MARKDOWN;
    }

    private function financialBriefing(): string
    {
        return <<<'MARKDOWN'
# Financial Briefing

Please analyze financial status:

1. **Revenue MTD** from dashboard
2. **Outstanding invoices** - list unpaid and overdue
3. **Accounts receivable** - total due
4. **Pipeline forecast** - expected revenue from leads

Identify:
- Invoices that need follow-up
- Clients with overdue balances
- Revenue at risk

Recommend collection actions prioritized by amount and age.
MARKDOWN;
    }
}
