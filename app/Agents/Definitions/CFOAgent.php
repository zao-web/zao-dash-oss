<?php

namespace App\Agents\Definitions;

use App\Agents\Concerns\HasApprovalGates;

/**
 * CFO Agent - Orchestrates all financial operations.
 *
 * This agent handles:
 * - Contractor payments via Wise
 * - Tax calculations and strategies
 * - Financial reporting and forecasting
 * - Bookkeeping oversight
 *
 * CRITICAL: All payment operations require owner approval.
 */
class CFOAgent extends BaseAgentDefinition
{
    use HasApprovalGates;

    /**
     * Override approval categories for financial operations.
     */
    protected array $approvalCategories = [
        'wise.payment',
        'contractor.invoice_approval',
        'financial.categorize',
    ];

    protected function getName(): string
    {
        return 'CFO Agent';
    }

    protected function getDescription(): string
    {
        return 'Your AI Chief Financial Officer. Manages contractor payments via Wise and provides financial forecasting. Payment operations require owner approval.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function getModel(): string
    {
        return 'opus'; // Use Opus for complex financial decisions
    }

    protected function getMaxBudget(): float
    {
        return 15.00; // Higher budget for comprehensive financial analysis
    }

    protected function requiresApproval(): bool
    {
        return true;
    }

    public function allowedTools(): array
    {
        return [
            // Wise Integration
            'wise-get-balances',
            'wise-get-recipients',
            'wise-initiate-transfer',

            // Financial Analysis
            'contractor-get-pending-payments',
            'financial-summary',
            'financial-forecast',

            // Existing QBO Tools
            'qbo-get-expenses',
            'qbo-get-categories',
            'qbo-categorize-expense',

            // Core Tools
            'create-task',
            'search-clients',
        ];
    }

    public function requiredSecrets(): array
    {
        return [
            'wise_api_token',
            'qbo_access_token',
        ];
    }

    public function configSchema(): array
    {
        return [
            'action' => 'required|string|in:pay_contractors,tax_status,tax_office_review,tax_return_forensics,bookkeeping,financial_summary,forecast',
            'contractor_id' => 'sometimes|integer',
            'quarter' => 'sometimes|integer|min:1|max:4',
            'year' => 'sometimes|integer|min:2020|max:2035',
        ];
    }

    public function systemPrompt(): string
    {
        $skillPrompt = $this->loadSkillPrompt();

        $context = <<<CONTEXT

## Current Context

Today is {$this->getFormattedDate()}.
Current quarter: Q{$this->getCurrentQuarter()} {$this->getCurrentYear()}

## Your Capabilities

### Contractor Payments
- Check pending contractor invoices and recurring payments
- Review Wise account balances
- Initiate wire transfers (requires approval)
- Track payment status

### Tax Management
- Calculate quarterly estimated tax payments
- Evaluate tax optimization strategies
- Coordinate prior-return evidence review, confidence gates, and bank-to-return tie-outs
- Track 1099 vendor payments
- Generate year-end tax packages

### Financial Analysis
- Provide cash position summaries
- Generate revenue/expense forecasts
- Identify AR/AP aging issues
- Monitor financial health metrics

## CRITICAL SAFETY RULES

1. **NEVER auto-execute payments** - All Wise transfers create approval requests
2. **Verify before acting** - Always check balances and recipient details before initiating transfers
3. **Explain financial implications** - Help the owner understand tax impacts and cash flow effects
4. **Flag uncertainties** - If you're unsure about a financial decision, ask for clarification
5. **Protect sensitive data** - Never expose full TINs, bank accounts, or API tokens in responses

## Response Format

When asked to perform an action:
1. Acknowledge the request
2. Gather relevant data using tools
3. Present findings clearly with numbers
4. If action requires approval, explain what will happen and create the request
5. Summarize next steps

CONTEXT;

        return $skillPrompt."\n".$context;
    }

    protected function getFormattedDate(): string
    {
        return now()->format('l, F j, Y');
    }

    protected function getCurrentQuarter(): int
    {
        return (int) ceil(now()->month / 3);
    }

    protected function getCurrentYear(): int
    {
        return now()->year;
    }
}
