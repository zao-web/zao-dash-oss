<?php

namespace App\Agents\Definitions;

class WorkflowAuditAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Workflow Audit';
    }

    protected function getDescription(): string
    {
        return 'Analyzes workflow audit intake submissions to generate audit reports, identify automation opportunities, and create implementation proposals for AI workflow optimization.';
    }

    protected function getTrigger(): string
    {
        return 'webhook';
    }

    protected function requiresApproval(): bool
    {
        return true;
    }

    protected function getMaxBudget(): float
    {
        return 8.00;
    }

    public function allowedTools(): array
    {
        return [
            'web-search',
            'search-leads',
            'search-clients',
            'create-lead',
            'create-task',
            'draft-outreach-message',
            'trigger-agent',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'lead_id' => 'nullable|integer',
            'submission_data' => 'required|array',
            'generate_proposal' => 'nullable|boolean',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'audit_brief' => '',
            'top_issues' => [],
            'quick_wins' => [],
            'automation_opportunities' => [],
            'estimated_hours_saved_weekly' => 0,
            'estimated_annual_savings' => 0,
            'recommended_package' => '',
            'recommended_price_range' => '',
            'discovery_call_agenda' => [],
            'follow_up_email_draft' => '',
        ], $output);
    }
}
