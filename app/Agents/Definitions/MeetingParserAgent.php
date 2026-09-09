<?php

namespace App\Agents\Definitions;

/**
 * Meeting Parser Agent
 *
 * Parses meeting transcripts and extracts:
 * - Action items with owners and deadlines
 * - Key decisions made
 * - Follow-up tasks
 * - Meeting summary
 */
class MeetingParserAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Meeting Parser';
    }

    protected function getDescription(): string
    {
        return 'Parse meeting transcripts and extract action items, decisions, and follow-up tasks.';
    }

    protected function getTrigger(): string
    {
        return 'webhook';
    }

    protected function requiresApproval(): bool
    {
        return false; // Action item creation is low risk
    }

    protected function getMaxBudget(): float
    {
        return 2.00; // Transcripts are typically small
    }

    public function allowedTools(): array
    {
        return ['api_calls'];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt();
    }

    public function configSchema(): array
    {
        return [
            'transcript' => 'required|string|min:50',
            'meeting_title' => 'nullable|string|max:255',
            'attendees' => 'nullable|array',
            'attendees.*' => 'string',
            'project_id' => 'nullable|integer|exists:projects,id',
        ];
    }

    public function processOutput(array $output): array
    {
        // Ensure output has expected structure
        return array_merge([
            'action_items' => [],
            'decisions' => [],
            'follow_ups' => [],
            'summary' => '',
        ], $output);
    }
}
