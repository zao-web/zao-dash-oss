<?php

namespace App\Agents\Definitions;

class BookkeepingAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Bookkeeping Agent';
    }

    protected function getDescription(): string
    {
        return 'Reviews and categorizes expenses in QuickBooks for tax optimization. Identifies uncategorized expenses, suggests appropriate categories, analyzes recurring subscriptions for build-vs-buy opportunities, and applies categorizations with approval.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 7 * * 1-5'; // Weekdays 7am
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
        return true; // Financial categorization changes need approval
    }

    public function allowedTools(): array
    {
        return [
            'qbo-get-expenses',
            'qbo-get-categories',
            'qbo-suggest-category',
            'qbo-categorize-expense',
            'qbo-analyze-subscriptions',
        ];
    }

    public function systemPrompt(): string
    {
        $skillPrompt = $this->loadSkillPrompt();

        $context = <<<CONTEXT

## Current Context

Today is {$this->getFormattedDate()}.

Your primary task is to:
1. Fetch any uncategorized expenses from the last 30 days
2. For each uncategorized expense, determine the appropriate category
3. Prioritize tax-deductible categories where legitimate
4. Submit categorization requests for approval
5. Flag any expenses that need human judgment

Remember: All categorization changes require approval before being applied.
CONTEXT;

        return $skillPrompt."\n".$context;
    }

    protected function getFormattedDate(): string
    {
        return now()->format('l, F j, Y');
    }
}
