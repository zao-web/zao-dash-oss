<?php

namespace App\Agents\Definitions;

class InvoiceAnalyzerAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Invoice Analyzer';
    }

    protected function getDescription(): string
    {
        return 'Analyzes time entries from Harvest, identifies billable work, and generates invoice drafts for review.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 9 * * 1'; // Monday 9am
    }

    protected function getModel(): string
    {
        return 'sonnet';
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    protected function requiresApproval(): bool
    {
        return true; // Invoice creation needs approval
    }

    public function allowedTools(): array
    {
        return [
            'get_time_entries',
            'get_client_rates',
            'create_invoice_draft',
            'get_project_budget',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }
}
