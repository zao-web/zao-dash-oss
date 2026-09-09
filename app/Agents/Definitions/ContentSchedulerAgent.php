<?php

namespace App\Agents\Definitions;

/**
 * Content Scheduler Agent
 *
 * Manages WordPress content publishing:
 * - Reviews pending content suggestions
 * - Optimizes content for publishing
 * - Schedules posts for optimal times
 * - Manages content calendar
 */
class ContentSchedulerAgent extends BaseAgentDefinition
{
    protected function getName(): string
    {
        return 'Content Scheduler';
    }

    protected function getDescription(): string
    {
        return 'Manage and schedule WordPress content publishing.';
    }

    protected function getTrigger(): string
    {
        return 'scheduled';
    }

    protected function getSchedule(): ?string
    {
        return '0 8 * * 1'; // Monday at 8am - weekly content review
    }

    protected function requiresApproval(): bool
    {
        return true; // Always review before publishing
    }

    protected function getMaxBudget(): float
    {
        return 3.00;
    }

    public function allowedTools(): array
    {
        return [
            'search_content',
            'web_search',
        ];
    }

    public function systemPrompt(): string
    {
        return $this->loadSkillPrompt(includeToneGuide: true);
    }

    public function configSchema(): array
    {
        return [
            'site_id' => 'nullable|integer|exists:wordpress_sites,id',
            'mode' => 'nullable|in:review,schedule,optimize,calendar',
            'content_ids' => 'nullable|array',
            'date_range' => 'nullable|array', // [start, end] for calendar view
            'auto_schedule' => 'nullable|boolean',
        ];
    }

    public function processOutput(array $output): array
    {
        return array_merge([
            'content_reviewed' => 0,
            'ready_to_publish' => [],
            'needs_revision' => [],
            'scheduled' => [],
            'calendar' => [],
            'recommendations' => [],
        ], $output);
    }
}
