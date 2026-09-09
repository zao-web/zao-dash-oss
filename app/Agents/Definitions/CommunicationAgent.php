<?php

namespace App\Agents\Definitions;

/**
 * Communication Agent
 *
 * Drafts professional communications:
 * - Client emails
 * - Slack messages
 * - Project updates
 * - Status reports
 *
 * All communications require approval before sending.
 */
class CommunicationAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Communication Agent';
    }

    protected function getDescription(): string
    {
        return 'Draft professional emails, Slack messages, and client communications.';
    }

    protected function getTrigger(): string
    {
        return 'manual';
    }

    protected function requiresApproval(): bool
    {
        return true; // All external communications require approval
    }

    protected function getMaxBudget(): float
    {
        return 2.00; // Communications are typically short
    }

    public function allowedTools(): array
    {
        return ['email', 'slack'];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'type' => 'required|in:email,slack,report',
            'recipient' => 'required|string',
            'context' => 'required|string|min:10',
            'tone' => 'nullable|in:formal,casual,urgent,friendly',
            'project_id' => 'nullable|integer|exists:projects,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'include_metrics' => 'nullable|boolean',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'subject' => '',
            'body' => '',
            'formatted_body' => '', // HTML version if applicable
            'attachments' => [],
            'suggested_send_time' => null,
        ], $output);
    }
}
